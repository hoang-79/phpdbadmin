<?php

namespace Tests\Feature\Services\Transfer;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;
use ZipArchive;

/**
 * Abnahme E-04: Der Excel-Export ist ein gültiges XLSX (Zip mit allen nötigen Teilen, jedes XML lesbar) und trägt die
 * Werte richtig — Kopfzeile fett, Zahlen als Zahlen, Datum als Datum, NULL leer, Text mit Sonderzeichen wörtlich,
 * Steuerzeichen entfernt, nie eine Formel. Je Treiber gegen die Herd-Dienste.
 */
#[Group('target-databases')]
class XlsxExportTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private const string MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/excel-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function drivers(): array
    {
        return [...self::serverDrivers(), 'SQLite' => [DatabaseDriver::Sqlite]];
    }

    #[DataProvider('drivers')]
    public function test_table_export_is_a_valid_workbook_with_typed_values(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->samples($driver);
        $writer->table('kunden')->where('id', 2)->update(['notiz' => '=HYPERLINK("https://example.com","Klick")']);
        $writer->table('kunden')->where('id', 3)->update(['notiz' => "Steuer\x01zeichen\x0B weg"]);
        $writer->table('kunden')->where('id', 6)->update(['notiz' => '_x0041_ bleibt']);
        $writer->table('kunden')->where('id', 9)->update(['notiz' => str_repeat('ab', 20000)]);

        $export = $this->runTransfer($connection, TransferKind::Export, TransferFormat::Xlsx, ['scope' => 'table', 'schema' => $schema, 'table' => 'kunden', 'binary' => 'base64']);
        $parts = $this->openWorkbook($this->exportedFile($export));

        $this->assertSame(['kunden'], $this->sheetNames($parts));
        $this->assertSame(['truncated_cells' => 1], array_intersect_key($export->result ?? [], ['truncated_cells' => true]));
        $cells = $this->cells($parts['xl/worksheets/sheet1.xml']);

        // Kopfzeile fett (Stil 1 = Schrift 1 mit <b/>).
        $this->assertSame(['id', 'name', 'notiz', 'betrag', 'wert', 'aktiv', 'foto', 'erstellt', 'meta'], array_column($cells[1], 'value'));
        $this->assertSame(['1'], array_values(array_unique(array_column($cells[1], 'style'))));
        $this->assertBoldFont($parts['xl/styles.xml'], 1);

        // Text mit Sonderzeichen wörtlich, auch \r\n und leerer Text (anders als NULL).
        $names = array_map(fn (int $row): ?string => $cells[$row]['B']['value'] ?? null, range(2, 11));
        $this->assertSame(array_values(self::TRICKY_TEXTS), $names);
        $this->assertSame('inlineStr', $cells[8]['B']['type'], 'leerer Text ist eine Zelle');
        $this->assertArrayNotHasKey('C', $cells[2], 'NULL ist eine leere Zelle');

        // Zahlen als Zahlen, Wahrheitswerte als solche.
        $this->assertSame('n', $cells[2]['A']['type']);
        $this->assertSame(1.0, (float) $cells[2]['A']['value']);
        $this->assertSame('n', $cells[4]['D']['type']);
        $this->assertSame(2469.0, (float) $cells[4]['D']['value']);
        $this->assertSame(0.30000000000000004, (float) $cells[3]['E']['value']);
        $this->assertSame(-1.0E-12, (float) $cells[4]['E']['value']);
        $this->assertSame(['b', '1'], [$cells[2]['F']['type'], $cells[2]['F']['value']]);
        $this->assertSame(['b', '0'], [$cells[3]['F']['type'], $cells[3]['F']['value']]);

        // Datum als Seriennummer mit Datumsformat; der fehlende Zeitpunkt (Zeile 6) bleibt leer.
        $this->assertSame('n', $cells[2]['H']['type']);
        $this->assertSame('3', $cells[2]['H']['style']);
        $this->assertEqualsWithDelta($this->serial('2026-10-01 13:40:00'), (float) $cells[2]['H']['value'], 1e-9);
        $this->assertArrayNotHasKey('H', $cells[6]);
        $this->assertDateFormat($parts['xl/styles.xml'], 3, 'yyyy\-mm\-dd\ hh:mm:ss');

        // Binärdaten als Base64, JSON als Text.
        $this->assertSame(base64_encode("\x00\x01\xFF\xFEabc"), $cells[2]['G']['value']);
        $this->assertJson((string) $cells[2]['I']['value']);

        // Nie eine Formel: «=HYPERLINK(…)» ist Text; Steuerzeichen fallen weg; _x0041_ bleibt wörtlich.
        $this->assertSame(['inlineStr', '=HYPERLINK("https://example.com","Klick")'], [$cells[3]['C']['type'], $cells[3]['C']['value']]);
        $this->assertStringNotContainsString('<f>', $parts['xl/worksheets/sheet1.xml']);
        $this->assertSame('Steuerzeichen weg', $cells[4]['C']['value']);
        $this->assertStringContainsString('_x005F_x0041_ bleibt', $parts['xl/worksheets/sheet1.xml']);

        // Excel fasst 32'767 Zeichen je Zelle.
        $this->assertSame(32767, mb_strlen((string) $cells[10]['C']['value']));
    }

    #[DataProvider('drivers')]
    public function test_whole_database_becomes_one_sheet_per_table_and_view(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [, $connection, $schema] = $this->samples($driver);

        $export = $this->runTransfer($connection, TransferKind::Export, TransferFormat::Xlsx, ['scope' => 'schema', 'schema' => $schema]);
        $parts = $this->openWorkbook($this->exportedFile($export));
        $names = $this->sheetNames($parts);

        $this->assertEqualsCanonicalizing(['kunden', 'bestellungen', 'positionen', 'protokoll', 'aktive_kunden'], $names);
        $this->assertSame('aktive_kunden', $names[4], 'Sichten zuletzt');
        $this->assertSame(10 + 3 + 5 + 4 + 5, $export->rows);

        $protocol = $this->cells($parts['xl/worksheets/sheet'.(array_search('protokoll', $names, true) + 1).'.xml']);
        $this->assertSame(['eintrag', 'zahl'], array_column($protocol[1], 'value'));
        $this->assertSame([], $protocol[4], 'Zeile nur aus NULL ohne Zellen');
        $this->assertSame(-5.0, (float) $protocol[5]['B']['value']);
    }

    /**
     * Öffnet die Datei als Zip und liest jedes Teil — die Teile, die Excel braucht, müssen da sein und als XML gelten.
     *
     * @return array<string, string>
     */
    private function openWorkbook(string $file): array
    {
        $this->assertStringStartsWith("PK\x03\x04", (string) file_get_contents($file, length: 4));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file, ZipArchive::RDONLY));

        $parts = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $parts[$name] = (string) $zip->getFromIndex($index);
        }
        $zip->close();

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'docProps/core.xml', 'docProps/app.xml'] as $required) {
            $this->assertArrayHasKey($required, $parts);
        }

        foreach ($parts as $name => $xml) {
            $this->document($xml, $name);
        }

        // Jedes Blatt hat einen Inhaltstyp und eine Beziehung in der Mappe.
        foreach (array_keys($parts) as $name) {
            if (str_starts_with($name, 'xl/worksheets/')) {
                $this->assertStringContainsString('PartName="/'.$name.'"', $parts['[Content_Types].xml']);
                $this->assertStringContainsString('Target="'.substr($name, 3).'"', $parts['xl/_rels/workbook.xml.rels']);
            }
        }

        return $parts;
    }

    /**
     * @param  array<string, string>  $parts
     * @return list<string>
     */
    private function sheetNames(array $parts): array
    {
        $xpath = new DOMXPath($this->document($parts['xl/workbook.xml'], 'workbook'));
        $xpath->registerNamespace('m', self::MAIN);

        return array_values(array_map(fn (DOMElement $sheet): string => $sheet->getAttribute('name'), iterator_to_array($xpath->query('//m:sheets/m:sheet') ?: [])));
    }

    /**
     * Zellen je Zeile und Spalte mit Typ (n, b, inlineStr), Stil und Wert — Inline-Text so, wie ein XML-Leser ihn
     * liest (Excel liest dazu `_xHHHH_`).
     *
     * @return array<int, array<string, array{type: string, style: string, value: string|null}>>
     */
    private function cells(string $sheet): array
    {
        $xpath = new DOMXPath($this->document($sheet, 'sheet'));
        $xpath->registerNamespace('m', self::MAIN);
        $rows = [];

        foreach ($xpath->query('//m:sheetData/m:row') ?: [] as $row) {
            assert($row instanceof DOMElement);
            $number = (int) $row->getAttribute('r');
            $rows[$number] = [];

            foreach ($xpath->query('m:c', $row) ?: [] as $cell) {
                assert($cell instanceof DOMElement);
                $type = $cell->getAttribute('t') ?: 'n';
                $value = $type === 'inlineStr' ? $xpath->query('m:is/m:t', $cell)?->item(0)?->textContent : $xpath->query('m:v', $cell)?->item(0)?->textContent;
                $rows[$number][(string) preg_replace('/\d+$/', '', $cell->getAttribute('r'))] = ['type' => $type, 'style' => $cell->getAttribute('s') ?: '0', 'value' => $value];
            }
        }

        return $rows;
    }

    private function document(string $xml, string $name): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded && $errors === [], $name.' ist kein gültiges XML');

        return $document;
    }

    private function assertBoldFont(string $styles, int $style): void
    {
        $xpath = new DOMXPath($this->document($styles, 'styles'));
        $xpath->registerNamespace('m', self::MAIN);
        $font = (int) $xpath->query('//m:cellXfs/m:xf')?->item($style)?->attributes?->getNamedItem('fontId')?->nodeValue;

        $this->assertSame(1, $xpath->query('//m:fonts/m:font['.($font + 1).']/m:b')?->length);
    }

    private function assertDateFormat(string $styles, int $style, string $format): void
    {
        $xpath = new DOMXPath($this->document($styles, 'styles'));
        $xpath->registerNamespace('m', self::MAIN);
        $id = $xpath->query('//m:cellXfs/m:xf')?->item($style)?->attributes?->getNamedItem('numFmtId')?->nodeValue;

        $this->assertSame($format, $xpath->query('//m:numFmts/m:numFmt[@numFmtId="'.$id.'"]')?->item(0)?->attributes?->getNamedItem('formatCode')?->nodeValue);
    }

    /** Seriennummer von Excel: Tage seit dem 30.12.1899, die Uhrzeit als Bruchteil. */
    private function serial(string $dateTime): float
    {
        $utc = new DateTimeZone('UTC');
        $moment = new DateTimeImmutable($dateTime, $utc);
        $days = (int) (new DateTimeImmutable('1899-12-30', $utc))->diff($moment)->days;

        return $days + ((int) $moment->format('H') * 3600 + (int) $moment->format('i') * 60 + (int) $moment->format('s')) / 86400;
    }

    /**
     * @return array{0: Connection, 1: DatabaseConnection, 2: string}
     */
    private function samples(DatabaseDriver $driver): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $writer = app(ConnectionFactory::class)->make($settings);
        $this->createTransferSamples($writer, $driver);

        return [$writer, $this->savedConnection($settings), $this->sampleSchemaName($driver)];
    }
}
