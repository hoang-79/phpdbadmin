<?php

namespace App\Services\Shape;

use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;

/**
 * Ordnet eine gelesene Spalte der Spaltenmethode von Laravel zu (`bigInteger`, `string`, `decimal` …) — Grundlage
 * für Änderungsskript (S-08) und Migration (S-09). Was Laravel nicht genau kennt, bekommt den nächsten passenden Typ
 * und einen Hinweis.
 *
 * Eigenheiten je Treiber: SQLite kennt nur «integer» (immer 64 Bit, deshalb `bigInteger`) und speichert keine Länge
 * bei `varchar`; PostgreSQL unterscheidet `dateTime` und `timestamp` nicht; MySQL legt `float(53)` als `double` an.
 */
final class LaravelTypes
{
    /** Länge für `string`, wenn der Treiber keine meldet und das Ziel eine braucht (Laravels Vorgabe). */
    public const int DEFAULT_STRING_LENGTH = 255;

    /**
     * @param  string  $typeName  Name des Typs ohne Länge («varchar», «int8»)
     * @param  string  $type  vollständiger Typ («varchar(100)», «bigint unsigned», «timestamp(0) without time zone»)
     * @param  list<string>|null  $allowed  Werte einer Aufzählung (aus dem Typ oder einem Check)
     */
    public static function for(DatabaseDriver $driver, string $typeName, string $type, ColumnKind $kind, bool $autoIncrement, ?array $allowed = null): LaravelColumn
    {
        $name = strtolower(trim($typeName));
        $full = strtolower(trim($type));
        $arguments = self::arguments($full);

        if ($allowed !== null && $allowed !== []) {
            return new LaravelColumn(str_starts_with($full, 'set(') ? 'set' : 'enum', ['allowed' => $allowed]);
        }

        if ($kind === ColumnKind::Json) {
            return new LaravelColumn($name === 'jsonb' ? 'jsonb' : 'json');
        }

        if ($kind === ColumnKind::Boolean) {
            return new LaravelColumn('boolean');
        }

        $integer = self::integer($driver, $name, $full, $autoIncrement);

        if ($integer !== null) {
            return $integer;
        }

        $base = (string) preg_replace('/[\s(].*$/', '', $name);

        return match (true) {
            in_array($base, ['decimal', 'numeric', 'dec', 'fixed'], true) => isset($arguments[0])
                ? new LaravelColumn('decimal', ['total' => (int) $arguments[0], 'places' => (int) ($arguments[1] ?? 0)])
                : new LaravelColumn('decimal', ['total' => 8, 'places' => 2], __('Genauigkeit unbekannt — als decimal(8, 2) übernommen.')),
            in_array($base, ['float', 'float4', 'real'], true) && ! ($driver === DatabaseDriver::Postgres && $base === 'float') => new LaravelColumn('float', ['precision' => 24]),
            in_array($base, ['double', 'float8', 'float'], true) || str_starts_with($full, 'double') => new LaravelColumn('double'),
            $base === 'money' => new LaravelColumn('decimal', ['total' => 19, 'places' => 2], __('Typ «money» — als decimal(19, 2) übernommen.')),
            in_array($base, ['varchar', 'character varying', 'nvarchar', 'varchar2', 'string'], true) || str_starts_with($full, 'character varying') => new LaravelColumn('string', ['length' => isset($arguments[0]) ? (int) $arguments[0] : null]),
            in_array($base, ['char', 'bpchar', 'character', 'nchar'], true) => new LaravelColumn('char', ['length' => isset($arguments[0]) ? (int) $arguments[0] : 1]),
            $base === 'tinytext' => new LaravelColumn('tinyText'),
            $base === 'mediumtext' => new LaravelColumn('mediumText'),
            $base === 'longtext' => new LaravelColumn('longText'),
            in_array($base, ['text', 'clob', 'citext', 'name'], true) => new LaravelColumn('text'),
            in_array($base, ['enum', 'set'], true) => new LaravelColumn($base, ['allowed' => self::enumValues($full)]),
            $base === 'date' => new LaravelColumn('date'),
            $base === 'year' => new LaravelColumn('year'),
            $base === 'timetz' || ($base === 'time' && str_contains($full, 'with time zone')) => new LaravelColumn('timeTz', ['precision' => self::precision($arguments)]),
            $base === 'time' => new LaravelColumn('time', ['precision' => self::precision($arguments)]),
            $base === 'timestamptz' || ($base === 'timestamp' && str_contains($full, 'with time zone')) => new LaravelColumn('timestampTz', ['precision' => self::precision($arguments)]),
            // PostgreSQL legt dateTime() und timestamp() gleich an; timestamp ist in Laravel-Projekten üblicher.
            $base === 'timestamp' => new LaravelColumn('timestamp', ['precision' => self::precision($arguments)]),
            $base === 'datetime' => new LaravelColumn('dateTime', ['precision' => self::precision($arguments)]),
            $base === 'binary' => new LaravelColumn('binary', ['length' => isset($arguments[0]) ? (int) $arguments[0] : null, 'fixed' => true]),
            $base === 'varbinary' => new LaravelColumn('binary', ['length' => isset($arguments[0]) ? (int) $arguments[0] : null]),
            in_array($base, ['blob', 'bytea'], true) => new LaravelColumn('binary'),
            in_array($base, ['tinyblob', 'mediumblob', 'longblob'], true) => new LaravelColumn('binary', [], __('Typ «:type» — Laravel legt «blob» an.', ['type' => $base])),
            $base === 'uuid' => new LaravelColumn('uuid'),
            $base === 'inet' => new LaravelColumn('ipAddress'),
            $base === 'macaddr' => new LaravelColumn('macAddress'),
            $kind === ColumnKind::Geometry => new LaravelColumn('geometry', ['subtype' => in_array($base, ['geometry', 'geography'], true) ? null : $base]),
            default => self::fallback($kind, $type),
        };
    }

    /**
     * Werte einer Aufzählung aus einem Check: SQLite «"status" in ('a', 'b')», PostgreSQL
     * «((status)::text = ANY ((ARRAY['a'::character varying, …])::text[]))» — so legt Laravels enum() sie an.
     *
     * @param  list<array{name: string, definition: string}>  $checks
     * @return list<string>|null
     */
    public static function enumFromChecks(string $column, array $checks): ?array
    {
        $quoted = preg_quote($column, '/');

        foreach ($checks as $check) {
            $definition = trim((string) preg_replace('/^check\s*/i', '', trim($check['definition'])));

            if (preg_match('/^\(*\s*["`]?'.$quoted.'["`]?\s*\)?\s+in\s*\((.*)\)\s*\)*$/is', $definition, $match) === 1) {
                return self::quotedList($match[1]);
            }

            if (preg_match('/^\(*\s*\(?'.$quoted.'\)?(?:::\w+(?:\s\w+)?)?\s*=\s*any\s*\(\(?\s*array\[(.*)\]/is', $definition, $match) === 1) {
                return self::quotedList((string) preg_replace("/'::[\\w\\s]+/", "'", $match[1]));
            }
        }

        return null;
    }

    private static function integer(DatabaseDriver $driver, string $name, string $full, bool $autoIncrement): ?LaravelColumn
    {
        $base = (string) preg_replace('/[\s(].*$/', '', $name);

        $type = match ($base) {
            'tinyint' => 'tinyInteger',
            'smallint', 'int2', 'smallserial', 'serial2' => 'smallInteger',
            'mediumint' => 'mediumInteger',
            'int', 'integer', 'int4', 'serial', 'serial4' => 'integer',
            'bigint', 'int8', 'bigserial', 'serial8' => 'bigInteger',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        // SQLite speichert jede Ganzzahl mit 64 Bit; «integer» heisst dort nichts über die Grösse.
        if ($driver === DatabaseDriver::Sqlite) {
            $type = 'bigInteger';
        }

        // Ohne «unsigned» bei PostgreSQL und SQLite: Autoinkrement-Schlüssel sind trotzdem nie negativ — so passen
        // sie zu Laravels increments() und zu Fremdschlüsseln in MySQL.
        $unsigned = str_contains($full, 'unsigned') || ($autoIncrement && ! in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true));

        return new LaravelColumn($type, array_filter(['unsigned' => $unsigned, 'autoIncrement' => $autoIncrement]));
    }

    private static function fallback(ColumnKind $kind, string $type): LaravelColumn
    {
        $fallback = match ($kind) {
            ColumnKind::Number => new LaravelColumn('decimal', ['total' => 30, 'places' => 10]),
            ColumnKind::DateTime => new LaravelColumn('dateTime'),
            ColumnKind::Binary => new LaravelColumn('binary'),
            ColumnKind::Identifier => new LaravelColumn('uuid'),
            default => new LaravelColumn('text'),
        };

        return new LaravelColumn($fallback->type, $fallback->parameters, __('Typ «:type» hat keine Spaltenmethode in Laravel — als :fallback übernommen.', ['type' => $type, 'fallback' => $fallback->type]));
    }

    /**
     * @return list<string>
     */
    private static function arguments(string $full): array
    {
        if (preg_match('/\(([^)]*)\)/', $full, $match) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $match[1])), fn (string $part): bool => is_numeric($part)));
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function precision(array $arguments): ?int
    {
        return isset($arguments[0]) ? (int) $arguments[0] : null;
    }

    /**
     * @return list<string>
     */
    private static function enumValues(string $full): array
    {
        return preg_match('/^\w+\((.*)\)$/s', $full, $match) === 1 ? self::quotedList($match[1]) : [];
    }

    /**
     * «'a', 'b''c'» → ['a', "b'c"].
     *
     * @return list<string>
     */
    private static function quotedList(string $list): array
    {
        preg_match_all("/'((?:[^']|'')*)'/", $list, $matches);

        return array_map(fn (string $value): string => str_replace("''", "'", $value), $matches[1]);
    }
}
