<?php

namespace App\Services\Transfer;

/**
 * CSV nach RFC 4180 mit einer Zusage, die PHPs fputcsv/fgetcsv nicht kennen: NULL und leerer Text bleiben
 * unterscheidbar. NULL ist ein leeres Feld ohne Anführungszeichen, leerer Text steht als `""`. So ergeben Export und
 * Re-Import dieselben Daten (E-01); ein leeres Feld aus Excel wird beim Import zu NULL.
 */
final class Csv
{
    public const string BOM = "\xEF\xBB\xBF";

    /** Erlaubte Trennzeichen mit ihrem Namen in Formularen. */
    public const array DELIMITERS = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab', '|' => 'pipe'];

    /**
     * Eine Zeile mit Zeilenende (CRLF). Felder in Anführungszeichen nur, wo nötig — und immer bei leerem Text.
     *
     * @param  list<string|null>  $fields
     */
    public static function line(array $fields, string $delimiter): string
    {
        return implode($delimiter, array_map(fn (?string $field): string => self::field($field, $delimiter), $fields))."\r\n";
    }

    public static function field(?string $value, string $delimiter): string
    {
        if ($value === null) {
            return '';
        }

        if ($value === '' || strpbrk($value, $delimiter."\"\r\n") !== false || trim($value) !== $value) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * Felder einer logischen Zeile (ohne Zeilenende); null für ein leeres Feld ohne Anführungszeichen. Nachsichtig
     * bei Unsauberem: Text nach einem schliessenden Anführungszeichen gehört noch zum Feld.
     *
     * @return list<string|null>
     */
    public static function parse(string $record, string $delimiter): array
    {
        $fields = [];
        $length = strlen($record);
        $position = 0;

        while (true) {
            if ($position < $length && $record[$position] === '"') {
                $value = '';
                $position++;

                while (true) {
                    $quote = strpos($record, '"', $position);

                    if ($quote === false) {
                        $value .= substr($record, $position);
                        $position = $length;
                        break;
                    }

                    $value .= substr($record, $position, $quote - $position);

                    if ($quote + 1 < $length && $record[$quote + 1] === '"') {
                        $value .= '"';
                        $position = $quote + 2;

                        continue;
                    }

                    $position = $quote + 1;
                    break;
                }

                $next = strpos($record, $delimiter, $position);

                if ($next === false) {
                    $fields[] = $value.substr($record, $position);

                    return $fields;
                }

                $fields[] = $value.substr($record, $position, $next - $position);
                $position = $next + 1;

                continue;
            }

            $next = strpos($record, $delimiter, $position);
            $piece = $next === false ? substr($record, $position) : substr($record, $position, $next - $position);
            $fields[] = $piece === '' ? null : $piece;

            if ($next === false) {
                return $fields;
            }

            $position = $next + 1;
        }
    }

    /**
     * Wahrscheinliches Trennzeichen: das häufigste der erlaubten in der ersten Zeile, ausserhalb von Anführungszeichen.
     */
    public static function guessDelimiter(string $firstLine): string
    {
        $outside = (string) preg_replace('/"(?:[^"]|"")*"/', '', $firstLine);
        $counts = [];

        foreach (array_keys(self::DELIMITERS) as $delimiter) {
            $counts[$delimiter] = substr_count($outside, $delimiter);
        }

        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }
}
