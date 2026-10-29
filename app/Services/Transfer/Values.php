<?php

namespace App\Services\Transfer;

use App\Enums\ColumnKind;
use Illuminate\Database\Connection;
use JsonException;

/**
 * Werte aus der Datenbank für die drei Exportformate (E-01): NULL, Wahrheitswerte, Zahlen, Text mit Umlauten und
 * Binärdaten — so, dass ein Re-Import dieselben Werte ergibt. PostgreSQL liefert bytea als Strom.
 */
final class Values
{
    public static function read(mixed $value): mixed
    {
        return is_resource($value) ? (string) stream_get_contents($value) : $value;
    }

    /** Binärdaten: Spalte mit Binärtyp oder ein Wert, der kein gültiges UTF-8 ist. */
    public static function isBinary(mixed $value, ColumnInfo $column): bool
    {
        return is_string($value) && ($column->binary || ! mb_check_encoding($value, 'UTF-8'));
    }

    /**
     * CSV: null bleibt null (leeres Feld ohne Anführungszeichen), Binärdaten als Base64, Wahrheitswerte als 1/0.
     */
    public static function csv(mixed $value, ColumnInfo $column): ?string
    {
        $value = self::read($value);

        return match (true) {
            $value === null => null,
            self::isBinary($value, $column) => base64_encode((string) $value),
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => self::float($value),
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /**
     * JSON: Zahlen und Wahrheitswerte als solche, JSON-Spalten als eingebettete Werte, Binärdaten als Base64-Text.
     */
    public static function json(mixed $value, ColumnInfo $column): mixed
    {
        $value = self::read($value);

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : self::float($value);
        }

        if (self::isBinary($value, $column)) {
            return base64_encode((string) $value);
        }

        if ($column->kind === ColumnKind::Json && is_string($value)) {
            try {
                return json_decode($value, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException) {
                return $value;
            }
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * SQL-Literal je Treiber über die Verbindung (Laravels escape: PDO::quote, Binärdaten als x'…' bzw. '\x…'::bytea,
     * Wahrheitswerte als 1/0 bzw. true/false).
     */
    public static function sql(Connection $target, mixed $value, ColumnInfo $column): string
    {
        $value = self::read($value);

        if (is_float($value)) {
            // Laravels escape nähme (string) mit 14 Stellen — verlustbehaftet.
            return is_finite($value) ? self::float($value) : $target->escape(self::float($value));
        }

        if (self::isBinary($value, $column) || (is_string($value) && str_contains($value, "\0"))) {
            return $target->escape($value, true);
        }

        return $target->escape(is_scalar($value) || $value === null ? $value : null);
    }

    /**
     * Kommazahlen verlustfrei (kürzeste Darstellung, die zurück dieselbe Zahl ergibt — `(string)` rundet auf 14
     * Stellen); NaN und Unendlich als Wort, wie PostgreSQL sie liest.
     */
    private static function float(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            is_infinite($value) => $value > 0 ? 'Infinity' : '-Infinity',
            default => var_export($value, true),
        };
    }
}
