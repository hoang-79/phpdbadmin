<?php

namespace App\Services\Shape;

use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;

/**
 * Vorgabe einer Spalte, vergleichbar über Treiber hinweg: ein Wert («literal») oder ein Ausdruck wie
 * CURRENT_TIMESTAMP. Jeder Treiber meldet Vorgaben anders — MySQL Text ohne Anführungszeichen, MariaDB mit
 * Anführungszeichen und «NULL» als Text, PostgreSQL mit Typumwandlung («'aktiv'::character varying», «true»),
 * SQLite Zahlen in Anführungszeichen.
 */
final readonly class DefaultValue
{
    private function __construct(
        public bool $isExpression,
        public string $value,
    ) {}

    public static function literal(string $value): self
    {
        return new self(false, $value);
    }

    public static function expression(string $sql): self
    {
        return new self(true, self::canonicalExpression($sql));
    }

    /**
     * Vorgabe aus Laravels Introspektion (`getColumns()['default']`); null ohne Vorgabe.
     */
    public static function fromIntrospection(?string $raw, DatabaseDriver $driver, bool $autoIncrement = false): ?self
    {
        if ($raw === null || $autoIncrement) {
            return null;
        }

        $value = trim($raw);

        if ($value === '' && $driver !== DatabaseDriver::MySql) {
            return null;
        }

        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        // PostgreSQL: Typumwandlung abstreifen («'aktiv'::character varying», «'0'::numeric», «NULL::text»).
        if ($driver === DatabaseDriver::Postgres && preg_match("/^\\(?('(?:[^']|'')*'|-?\\d+(?:\\.\\d+)?|NULL)\\)?::[\\w\\s\"\\[\\]().,]+$/i", $value, $match) === 1) {
            $value = $match[1];

            if (strcasecmp($value, 'NULL') === 0) {
                return null;
            }
        }

        // Äussere Klammern: «(-1)» in PostgreSQL, «(datetime('now'))» in SQLite, «(uuid())» in MySQL.
        while (self::isWrapped($value)) {
            $value = trim(substr($value, 1, -1));
        }

        if (preg_match("/^'((?:[^']|'')*)'$/s", $value, $match) === 1) {
            return self::literal(str_replace("''", "'", $match[1]));
        }

        if (is_numeric($value)) {
            return self::literal($value);
        }

        if ($driver === DatabaseDriver::Postgres && in_array(strtolower($value), ['true', 'false'], true)) {
            return self::literal(strtolower($value) === 'true' ? '1' : '0');
        }

        // MySQL meldet Text ohne Anführungszeichen; Ausdrücke erkennt man nur an der Form (Funktion oder CURRENT_*).
        if ($driver === DatabaseDriver::MySql && ! self::looksLikeExpression($value)) {
            return self::literal($value);
        }

        return self::expression($value);
    }

    /**
     * Gleiche Vorgabe? Zahlen nach Wert («0» = «0.00»), Wahrheitswerte nach Bedeutung («1» = «true»), Ausdrücke ohne
     * Rücksicht auf Gross- und Kleinschreibung.
     */
    public static function same(?self $a, ?self $b, ColumnKind $kind): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if ($a->isExpression !== $b->isExpression) {
            return false;
        }

        if ($a->isExpression) {
            return strcasecmp($a->value, $b->value) === 0;
        }

        if ($kind === ColumnKind::Boolean) {
            return self::truthy($a->value) === self::truthy($b->value);
        }

        if (is_numeric($a->value) && is_numeric($b->value)) {
            return (float) $a->value === (float) $b->value;
        }

        return $a->value === $b->value;
    }

    /** CURRENT_TIMESTAMP und Verwandte in einer Schreibweise — alle vier Treiber verstehen sie. */
    public function isPortable(): bool
    {
        return ! $this->isExpression || preg_match('/^CURRENT_(TIMESTAMP|DATE|TIME)(\(\d+\))?$/', $this->value) === 1;
    }

    /** Für Anzeige und Vergleichslisten: Werte in Anführungszeichen, Ausdrücke wie sie sind. */
    public function display(): string
    {
        return $this->isExpression ? $this->value : "'".str_replace("'", "''", $this->value)."'";
    }

    public function asBoolean(): bool
    {
        return self::truthy($this->value);
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value, "' ")), ['1', 'true', 't', 'yes', 'y', 'on', "b'1'"], true);
    }

    private static function looksLikeExpression(string $value): bool
    {
        return preg_match('/^[a-z_][a-z0-9_]*\s*\(.*\)$/is', $value) === 1
            || preg_match('/^current_(timestamp|date|time)\b/i', $value) === 1;
    }

    private static function canonicalExpression(string $sql): string
    {
        if (preg_match('/^(current_timestamp|now|localtimestamp)\s*(?:\(\s*(\d*)\s*\))?$/i', trim($sql), $match) === 1) {
            $precision = (int) ($match[2] ?? 0);

            return 'CURRENT_TIMESTAMP'.($precision > 0 ? '('.$precision.')' : '');
        }

        if (preg_match('/^(current_date|curdate)\s*(\(\s*\))?$/i', trim($sql)) === 1) {
            return 'CURRENT_DATE';
        }

        if (preg_match('/^(current_time|curtime)\s*(\(\s*\))?$/i', trim($sql)) === 1) {
            return 'CURRENT_TIME';
        }

        return trim($sql);
    }

    /** Ganz in Klammern, die zusammengehören — «(a) + (b)» ist es nicht. */
    private static function isWrapped(string $value): bool
    {
        if (! str_starts_with($value, '(') || ! str_ends_with($value, ')')) {
            return false;
        }

        $depth = 0;
        $quoted = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === "'") {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '(') {
                $depth++;
            } elseif (! $quoted && $char === ')') {
                $depth--;

                if ($depth === 0 && $i < $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }
}
