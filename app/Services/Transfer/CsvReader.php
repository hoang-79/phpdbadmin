<?php

namespace App\Services\Transfer;

use Generator;
use RuntimeException;

/**
 * Liest eine CSV-Datei Satz für Satz (E-02): UTF-8 mit oder ohne BOM oder Windows-1252, Trennzeichen nach Wahl,
 * Zeilenumbrüche in Feldern mit Anführungszeichen. Jeder Satz kommt mit der Nummer der Zeile, in der er beginnt —
 * so nennt der Probelauf Fehler so, wie ein Editor die Datei zeigt. Leere Zeilen zählen mit, ergeben aber keinen Satz.
 */
final class CsvReader
{
    public const string UTF8 = 'utf-8';

    public const string WINDOWS_1252 = 'windows-1252';

    /** Ein Feld mit Zeilenumbrüchen darf so viele Zeilen umfassen — mehr deutet auf ein vergessenes Anführungszeichen. */
    private const int MAX_LINES_PER_RECORD = 10_000;

    private int $bytesRead = 0;

    public function __construct(
        private readonly string $path,
        private readonly string $delimiter,
        private readonly string $encoding = self::UTF8,
    ) {}

    /**
     * Sätze als Zeilennummer => Felder; null steht für ein leeres Feld ohne Anführungszeichen. Ungültige Zeichen
     * (UTF-8 erwartet, aber keines) bleiben im Feld — der Aufrufer meldet sie mit Zeilennummer.
     *
     * @return Generator<int, list<string|null>>
     */
    public function records(): Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('CSV file not readable');
        }

        $this->bytesRead = 0;
        $line = 0;

        try {
            while (($text = fgets($handle)) !== false) {
                $line++;
                $start = $line;
                $this->bytesRead += strlen($text);

                if ($start === 1 && str_starts_with($text, Csv::BOM)) {
                    $text = substr($text, 3);
                }

                // Ungerade Zahl von Anführungszeichen: ein Feld geht über das Zeilenende hinaus.
                $lines = 1;
                while (substr_count($text, '"') % 2 === 1 && $lines < self::MAX_LINES_PER_RECORD && ($more = fgets($handle)) !== false) {
                    $line++;
                    $lines++;
                    $this->bytesRead += strlen($more);
                    $text .= $more;
                }

                $record = self::withoutLineEnd($text);

                if ($record === '') {
                    continue;
                }

                $fields = Csv::parse($record, $this->delimiter);

                yield $start => $this->encoding === self::WINDOWS_1252
                    ? array_map(fn (?string $field): ?string => $field === null ? null : mb_convert_encoding($field, 'UTF-8', 'Windows-1252'), $fields)
                    : $fields;
            }
        } finally {
            fclose($handle);
        }
    }

    /** Gelesene Bytes (für den Fortschritt). */
    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    /**
     * Was der Kopf der Datei verrät: BOM, Kodierung (UTF-8, wenn die ersten 64 KB gültig sind), Trennzeichen.
     *
     * @return array{bom: bool, encoding: string, delimiter: string}
     */
    public static function sniff(string $path): array
    {
        $head = (string) file_get_contents($path, length: 65536);
        $bom = str_starts_with($head, Csv::BOM);
        $body = $bom ? substr($head, 3) : $head;
        $firstLine = strtok($body, "\n");

        return [
            'bom' => $bom,
            'encoding' => $bom || self::isUtf8Prefix($body) ? self::UTF8 : self::WINDOWS_1252,
            'delimiter' => Csv::guessDelimiter($firstLine === false ? '' : $firstLine),
        ];
    }

    /**
     * Gültiges UTF-8 — ein am Ende abgeschnittenes Zeichen (Grenze der 64 KB) zählt nicht als Fehler.
     */
    private static function isUtf8Prefix(string $text): bool
    {
        for ($cut = 0; $cut < 4 && $cut < strlen($text); $cut++) {
            if (mb_check_encoding(substr($text, 0, strlen($text) - $cut), 'UTF-8')) {
                return true;
            }
        }

        return $text === '';
    }

    private static function withoutLineEnd(string $text): string
    {
        if (str_ends_with($text, "\r\n")) {
            return substr($text, 0, -2);
        }

        return str_ends_with($text, "\n") || str_ends_with($text, "\r") ? substr($text, 0, -1) : $text;
    }
}
