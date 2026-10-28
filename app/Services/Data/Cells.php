<?php

namespace App\Services\Data;

use App\Enums\ColumnKind;

/**
 * Werte für die Oberfläche (D-01, O-04): gleich in allen vier Datenbanken. Wahrheitswerte als true/false (MySQL
 * liefert 1/0), Binärdaten nur als Grösse und Anfang, lange Texte gekürzt, Ganzzahlen über 2^53 als Text (sonst
 * rundet JavaScript sie), PostgreSQL-bytea als Strom gelesen.
 *
 * @phpstan-type Cell string|int|float|bool|null|array{binary: int, hex: string}|array{text: string, length: int}
 */
final class Cells
{
    public const int GRID_TEXT = 1_000;

    public const int DETAIL_TEXT = 200_000;

    /**
     * @return Cell
     */
    public static function encode(mixed $value, ColumnKind $kind, int $textLimit = self::GRID_TEXT): mixed
    {
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        if ($value === null) {
            return null;
        }

        if ($kind === ColumnKind::Boolean && (is_bool($value) || in_array($value, [0, 1, '0', '1', 't', 'f'], true))) {
            return in_array($value, [true, 1, '1', 't'], true);
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return abs($value) > 9_007_199_254_740_991 ? (string) $value : $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        $string = is_scalar($value) ? (string) $value : '';

        if ($kind === ColumnKind::Binary || ! mb_check_encoding($string, 'UTF-8')) {
            return ['binary' => strlen($string), 'hex' => bin2hex(substr($string, 0, 16))];
        }

        if (mb_strlen($string) > $textLimit) {
            return ['text' => mb_substr($string, 0, $textLimit), 'length' => mb_strlen($string)];
        }

        return $string;
    }

    /**
     * Schlüsselwert als Text für Adressen und das Blättern — verlustfrei auch für grosse Zahlen.
     */
    public static function key(mixed $value): ?string
    {
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }
}
