<?php

namespace App\Services\Transfer\Writers;

use App\Enums\ColumnKind;
use App\Services\Transfer\ColumnInfo;
use App\Services\Transfer\ExportTable;
use App\Services\Transfer\Values;
use RuntimeException;
use ZipArchive;

/**
 * Excel (E-04): eine Arbeitsmappe im Format Office Open XML, ohne fremde Bibliothek. Je Tabelle ein Blatt mit fetter,
 * fixierter Kopfzeile. Die Zeilen gehen als Strom in eine Datei je Blatt; erst am Ende packt ZipArchive alles
 * zusammen — der Speicher wächst nicht mit der Tabelle (F-8).
 *
 * Werte: Zahlen als Zahlen (bis 15 Stellen — mehr kann Excel nicht genau, dann als Text), Datum und Zeit als Datum
 * (Seriennummer mit Format; eine Zeitzone fällt weg, die Uhrzeit bleibt so, wie der Server sie liefert), NULL als
 * leere Zelle, Wahrheitswerte als WAHR/FALSCH, alles andere als Text. Text steht immer als Inline-Text in der Zelle,
 * nie als Formel — «=HYPERLINK(…)» bleibt Text (keine Formel-Injektion). Zeichen, die XML nicht erlaubt
 * (Steuerzeichen), fallen weg; `_x0041_` bleibt wörtlich stehen, statt von Excel als «A» gelesen zu werden.
 */
final class XlsxWriter implements ExportWriter
{
    /** Mehr Zeichen fasst eine Zelle in Excel nicht. */
    public const int MAX_CELL_CHARACTERS = 32767;

    /** Zeilen je Blatt inklusive Kopfzeile; darüber geht es auf einem weiteren Blatt weiter. */
    public const int MAX_ROWS = 1048576;

    /** Ganzzahlen und Dezimalzahlen mit mehr Ziffern verlören in Excel Stellen. */
    private const int MAX_DIGITS = 15;

    private const int STYLE_HEADER = 1;

    private const int STYLE_DATE = 2;

    private const int STYLE_DATETIME = 3;

    private const int STYLE_TIME = 4;

    private const string XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

    /**
     * Stile: 0 Standard, 1 fett (Kopfzeile), 2 Datum, 3 Datum und Zeit, 4 Zeit. Datum in ISO-Schreibweise wie in der
     * Datenbank — eindeutig in jeder Sprache von Excel.
     */
    private const string STYLES = self::XML.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm:ss"/></numFmts>'
        .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="5">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="21" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'</cellXfs>'
        .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        .'</styleSheet>';

    /** @var list<array{name: string, file: string}> */
    private array $sheets = [];

    /** @var resource|null */
    private $sheet = null;

    private ?ExportTable $table = null;

    /** @var list<ColumnInfo> */
    private array $columns = [];

    /** @var list<string> */
    private array $letters = [];

    private int $rowNumber = 0;

    private int $part = 1;

    /** Zellen, deren Text für Excel gekürzt werden musste. */
    public int $truncatedCells = 0;

    /**
     * @param  resource  $stream  Ziel der fertigen Datei
     * @param  string  $workDirectory  Ordner für die Blätter, bis sie gepackt sind (wird danach gelöscht)
     */
    public function __construct(
        private $stream,
        private readonly string $workDirectory,
        private readonly bool $skipBinary = false,
    ) {}

    public function begin(): void
    {
        if (! is_dir($this->workDirectory) && ! mkdir($this->workDirectory, 0700, true) && ! is_dir($this->workDirectory)) {
            throw new RuntimeException('XLSX work directory not writable');
        }
    }

    public function beginTable(ExportTable $table): void
    {
        $this->table = $table;
        $this->part = 1;
        $this->columns = array_values(array_filter(
            $table->dataColumns(),
            fn (ColumnInfo $column): bool => ! ($this->skipBinary && $column->binary),
        ));
        $this->letters = array_map(fn (int $index): string => self::columnLetter($index), array_keys($this->columns));
        $this->openSheet();
    }

    public function wantsRows(ExportTable $table): bool
    {
        return true;
    }

    public function row(array $row): void
    {
        if ($this->rowNumber >= self::MAX_ROWS) {
            $this->closeSheet();
            $this->part++;
            $this->openSheet();
        }

        $this->rowNumber++;
        $cells = '';

        foreach ($this->columns as $index => $column) {
            $cells .= $this->cell($this->letters[$index].$this->rowNumber, $row[$column->name] ?? null, $column);
        }

        $this->write('<row r="'.$this->rowNumber.'">'.$cells.'</row>');
    }

    public function endTable(): void
    {
        $this->closeSheet();
    }

    public function finish(): void
    {
        $archive = $this->workDirectory.'/mappe.xlsx';
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('XLSX archive not writable');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', self::XML.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>');
        $zip->addFromString('docProps/core.xml', self::XML.'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:creator>phpdbadmin</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created>'
            .'</cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', self::XML.'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>phpdbadmin</Application></Properties>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelations());
        $zip->addFromString('xl/styles.xml', self::STYLES);

        foreach ($this->sheets as $position => $sheet) {
            $zip->addFile($sheet['file'], 'xl/worksheets/sheet'.($position + 1).'.xml');
        }

        if (! $zip->close()) {
            throw new RuntimeException('XLSX archive not written');
        }

        $packed = fopen($archive, 'rb');

        if ($packed === false) {
            throw new RuntimeException('XLSX archive not readable');
        }

        stream_copy_to_stream($packed, $this->stream);
        fclose($packed);

        foreach ($this->sheets as $sheet) {
            @unlink($sheet['file']);
        }

        @unlink($archive);
        @rmdir($this->workDirectory);
    }

    private function openSheet(): void
    {
        $file = $this->workDirectory.'/blatt-'.(count($this->sheets) + 1).'.xml';
        $handle = fopen($file, 'wb');

        if ($handle === false || $this->table === null) {
            throw new RuntimeException('XLSX sheet not writable');
        }

        $this->sheet = $handle;
        $this->sheets[] = ['name' => $this->sheetName($this->table->name, $this->part), 'file' => $file];
        $this->rowNumber = 1;

        $widths = '';
        foreach ($this->columns as $index => $column) {
            $widths .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.self::width($column).'" customWidth="1"/>';
        }

        $header = '';
        foreach ($this->columns as $index => $column) {
            $header .= '<c r="'.$this->letters[$index].'1" t="inlineStr" s="'.self::STYLE_HEADER.'"><is><t xml:space="preserve">'.$this->text($column->name).'</t></is></c>';
        }

        $this->write(self::XML.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($widths === '' ? '' : '<cols>'.$widths.'</cols>')
            .'<sheetData><row r="1">'.$header.'</row>');
    }

    private function closeSheet(): void
    {
        if ($this->sheet === null) {
            return;
        }

        $this->write('</sheetData></worksheet>');
        fclose($this->sheet);
        $this->sheet = null;
    }

    private function write(string $xml): void
    {
        if ($this->sheet === null || fwrite($this->sheet, $xml) === false) {
            throw new RuntimeException('XLSX sheet not writable');
        }
    }

    /**
     * Eine Zelle — oder nichts für NULL (leere Zelle).
     */
    private function cell(string $reference, mixed $value, ColumnInfo $column): string
    {
        $value = Values::read($value);

        if ($value === null) {
            return '';
        }

        if (Values::isBinary($value, $column)) {
            return $this->inline($reference, base64_encode((string) $value));
        }

        if (is_bool($value) || ($column->kind === ColumnKind::Boolean && in_array($value, [0, 1, '0', '1', 't', 'f'], true))) {
            return '<c r="'.$reference.'" t="b"><v>'.(in_array($value, [true, 1, '1', 't'], true) ? '1' : '0').'</v></c>';
        }

        if (is_int($value) || is_float($value) || $column->kind === ColumnKind::Number) {
            $number = self::number($value, approximate: preg_match('/^(float|double|real)/i', $column->type) === 1);

            if ($number !== null) {
                return '<c r="'.$reference.'"><v>'.$number.'</v></c>';
            }
        }

        if ($column->kind === ColumnKind::DateTime && is_string($value)) {
            $date = self::date($value);

            if ($date !== null) {
                return '<c r="'.$reference.'" s="'.$date[1].'"><v>'.$date[0].'</v></c>';
            }
        }

        return $this->inline($reference, is_scalar($value) ? (string) $value : '');
    }

    private function inline(string $reference, string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = base64_encode($text);
        }

        if (mb_strlen($text) > self::MAX_CELL_CHARACTERS) {
            $text = mb_substr($text, 0, self::MAX_CELL_CHARACTERS);
            $this->truncatedCells++;
        }

        return '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$this->text($text).'</t></is></c>';
    }

    /**
     * Text für XML: verbotene Zeichen weg, `&<>"` maskiert, Wagenrücklauf als Zeichenreferenz (ein XML-Leser machte
     * sonst aus «\r\n» ein «\n»), und `_xHHHH_` so maskiert, dass Excel es nicht als Zeichencode liest.
     */
    private function text(string $value): string
    {
        $clean = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        $clean = (string) preg_replace('/_(x[0-9A-Fa-f]{4}_)/', '_x005F_$1', $clean);

        return str_replace("\r", '&#13;', htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Zahl so, dass Excel sie genau liest — sonst null (dann als Text). Kommazahlen mit Gleitkomma-Typ (`double`,
     * `real`) sind ohnehin eine Gleitkommazahl wie in Excel und gehen mit allen Stellen; genaue Typen (`decimal`,
     * `bigint`) nur bis 15 Stellen.
     *
     * @param  bool  $approximate  Spalte mit Gleitkomma-Typ
     */
    private static function number(mixed $value, bool $approximate): ?string
    {
        if (is_float($value)) {
            return is_finite($value) ? var_export($value, true) : null;
        }

        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : null);

        if ($text === null || preg_match('/^[+-]?(\d+)(?:\.(\d+))?(?:[eE][+-]?\d{1,3})?$/', $text, $match) !== 1) {
            return null;
        }

        // Es zählen die Ziffern zwischen der ersten und der letzten, die nicht null sind.
        if (! $approximate && strlen(trim($match[1].($match[2] ?? ''), '0')) > self::MAX_DIGITS) {
            return null;
        }

        return ltrim($text, '+');
    }

    /**
     * Datum («2026-10-29»), Zeitpunkt («2026-10-29 13:45:00.123+01») oder Uhrzeit («13:45:00») als Seriennummer von
     * Excel mit passendem Stil. Vor dem 1. März 1900 (Excel zählt dort den 29. Februar 1900 mit), nach dem Jahr 9999,
     * «0000-00-00» und Zeiten über 24 Stunden bleiben Text.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function date(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(\.\d+)?)?)?\s*(?:Z|[+-]\d{2}(?::?\d{2}(?::?\d{2})?)?)?$/', $value, $match) === 1) {
            [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];

            if (! checkdate($month, $day, $year) || $year > 9999 || sprintf('%04d-%02d-%02d', $year, $month, $day) < '1900-03-01') {
                return null;
            }

            $serial = self::daysFromCivil($year, $month, $day) - self::daysFromCivil(1899, 12, 30);

            if (! isset($match[4]) || $match[4] === '') {
                return [(string) $serial, self::STYLE_DATE];
            }

            $fraction = self::dayFraction((int) $match[4], (int) ($match[5] ?? 0), (int) ($match[6] ?? 0), (float) ('0'.($match[7] ?? '')));

            return $fraction === null ? null : [self::decimal($serial + $fraction), self::STYLE_DATETIME];
        }

        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2})(\.\d+)?)?\s*(?:[+-]\d{2}(?::?\d{2})?)?$/', $value, $match) === 1) {
            $fraction = self::dayFraction((int) $match[1], (int) $match[2], (int) ($match[3] ?? 0), (float) ('0'.($match[4] ?? '')));

            return $fraction === null ? null : [self::decimal($fraction), self::STYLE_TIME];
        }

        return null;
    }

    private static function dayFraction(int $hour, int $minute, int $second, float $fraction): ?float
    {
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return ($hour * 3600 + $minute * 60 + $second + $fraction) / 86400;
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }

    /** Tage seit 1970-01-01 im gregorianischen Kalender (Algorithmus von Howard Hinnant). */
    private static function daysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146097 + $dayOfEra - 719468;
    }

    /** Spaltenbuchstaben: 0 → A, 25 → Z, 26 → AA. */
    private static function columnLetter(int $index): string
    {
        $letters = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letters = chr(65 + ($index - 1) % 26).$letters;
        }

        return $letters;
    }

    private static function width(ColumnInfo $column): int
    {
        $byKind = match ($column->kind) {
            ColumnKind::Number => 14,
            ColumnKind::Boolean => 10,
            ColumnKind::DateTime => 20,
            ColumnKind::Identifier => 38,
            default => 30,
        };

        return max($byKind, min(60, mb_strlen($column->name) + 4));
    }

    /**
     * Name des Blatts: höchstens 31 Zeichen, ohne `: \ / ? * [ ]`, nicht mit Apostroph am Rand, eindeutig ohne
     * Rücksicht auf Gross- und Kleinschreibung; Fortsetzungen heissen «Name (2)».
     */
    private function sheetName(string $table, int $part): string
    {
        $suffix = $part > 1 ? ' ('.$part.')' : '';
        $base = trim((string) preg_replace('/[:\\\\\/?*\[\]\x00-\x1F]/u', '_', $table), "' ");
        $base = $base === '' || strcasecmp($base, 'History') === 0 ? 'Tabelle' : $base;
        $taken = array_map(fn (array $sheet): string => mb_strtolower($sheet['name']), $this->sheets);

        for ($attempt = 1; ; $attempt++) {
            $extra = $attempt > 1 ? ' '.$attempt : '';
            $name = mb_substr($base, 0, 31 - mb_strlen($suffix.$extra)).$suffix.$extra;

            if (! in_array(mb_strtolower($name), $taken, true)) {
                return $name;
            }
        }
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach (array_keys($this->sheets) as $position) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($position + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return self::XML.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .$sheets
            .'</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $position => $sheet) {
            $sheets .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.($position + 1).'" r:id="rId'.($position + 1).'"/>';
        }

        return self::XML.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView/></bookViews><sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRelations(): string
    {
        $relations = '';
        foreach (array_keys($this->sheets) as $position) {
            $relations .= '<Relationship Id="rId'.($position + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($position + 1).'.xml"/>';
        }

        $relations .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return self::XML.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>';
    }
}
