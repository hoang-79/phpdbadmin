<?php

namespace App\Services\Schema;

use App\Enums\DatabaseDriver;

/**
 * Typen, die das Formular je Treiber anbietet (S-02, S-03): der Name im Original des Treibers als Beschriftung, dahinter
 * eine Methode von Laravels Blueprint. Dazu «eigener Typ» für alles andere. Umgekehrt ordnet specOf() eine bestehende
 * Spalte einem Eintrag der Liste zu — nur wenn Laravel daraus wieder genau denselben Typ erzeugt; sonst bleibt der
 * Typ wörtlich als eigener Typ stehen.
 *
 * @phpstan-type TypeOption array{key: string, label: string, params: 'length'|'precision'|'values'|null, autoIncrement: bool, textual: bool}
 */
final class ColumnTypes
{
    public const string CUSTOM = 'custom';

    /** Typen mit Länge, mit Genauigkeit, mit Auswahl. */
    private const array LENGTH = ['string', 'char'];

    private const array PRECISION = ['decimal'];

    private const array VALUES = ['enum'];

    /** Ganzzahlen — nur sie können Auto-Increment sein. */
    private const array INTEGERS = ['integer', 'unsignedInteger', 'bigInteger', 'unsignedBigInteger', 'smallInteger', 'unsignedSmallInteger', 'tinyInteger'];

    /** Texttypen — nur sie tragen eine Kollation. */
    private const array TEXTUAL = ['string', 'char', 'text', 'mediumText', 'longText', 'enum'];

    /**
     * Schlüssel → Beschriftung je Treiber, in der Reihenfolge der Auswahl.
     *
     * @return array<string, string>
     */
    private static function labels(DatabaseDriver $driver): array
    {
        return match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => [
                'string' => 'varchar',
                'char' => 'char',
                'text' => 'text',
                'mediumText' => 'mediumtext',
                'longText' => 'longtext',
                'integer' => 'int',
                'unsignedInteger' => 'int unsigned',
                'bigInteger' => 'bigint',
                'unsignedBigInteger' => 'bigint unsigned',
                'smallInteger' => 'smallint',
                'unsignedSmallInteger' => 'smallint unsigned',
                'tinyInteger' => 'tinyint',
                'decimal' => 'decimal',
                'double' => 'double',
                'boolean' => 'tinyint(1)',
                'date' => 'date',
                'dateTime' => 'datetime',
                'timestamp' => 'timestamp',
                'time' => 'time',
                'json' => $driver === DatabaseDriver::MariaDb ? 'json (longtext)' : 'json',
                'binary' => 'blob',
                'uuid' => $driver === DatabaseDriver::MariaDb ? 'uuid' : 'char(36)',
                'enum' => 'enum',
            ],
            DatabaseDriver::Postgres => [
                'string' => 'varchar',
                'char' => 'char',
                'text' => 'text',
                'integer' => 'integer',
                'bigInteger' => 'bigint',
                'smallInteger' => 'smallint',
                'decimal' => 'numeric',
                'double' => 'double precision',
                'boolean' => 'boolean',
                'date' => 'date',
                'timestamp' => 'timestamp',
                'timestampTz' => 'timestamptz',
                'time' => 'time',
                'json' => 'json',
                'jsonb' => 'jsonb',
                'binary' => 'bytea',
                'uuid' => 'uuid',
            ],
            DatabaseDriver::Sqlite => [
                'string' => 'varchar',
                'text' => 'text',
                'integer' => 'integer',
                'decimal' => 'numeric',
                'double' => 'double',
                'boolean' => 'tinyint(1)',
                'date' => 'date',
                'dateTime' => 'datetime',
                'time' => 'time',
                'json' => 'json (text)',
                'binary' => 'blob',
            ],
        };
    }

    /**
     * Auswahl für das Formular, am Ende «eigener Typ».
     *
     * @return list<TypeOption>
     */
    public static function options(DatabaseDriver $driver): array
    {
        $options = [];

        foreach (self::labels($driver) as $key => $label) {
            $options[] = [
                'key' => $key,
                'label' => $label,
                'params' => self::params($key),
                'autoIncrement' => in_array($key, self::INTEGERS, true),
                'textual' => in_array($key, self::TEXTUAL, true),
            ];
        }

        $options[] = ['key' => self::CUSTOM, 'label' => __('eigener Typ'), 'params' => null, 'autoIncrement' => false, 'textual' => false];

        return $options;
    }

    public static function offers(DatabaseDriver $driver, string $key): bool
    {
        return $key === self::CUSTOM || array_key_exists($key, self::labels($driver));
    }

    /**
     * @return 'length'|'precision'|'values'|null
     */
    public static function params(string $key): ?string
    {
        return match (true) {
            in_array($key, self::LENGTH, true) => 'length',
            in_array($key, self::PRECISION, true) => 'precision',
            in_array($key, self::VALUES, true) => 'values',
            default => null,
        };
    }

    public static function isInteger(string $key): bool
    {
        return in_array($key, self::INTEGERS, true);
    }

    public static function isTextual(string $key): bool
    {
        return in_array($key, self::TEXTUAL, true);
    }

    /**
     * Eine bestehende Spalte (Laravels getColumns()) als Eintrag des Formulars.
     *
     * @param  array<string, mixed>  $column
     * @param  bool  $isJson  MariaDB: longtext mit json_valid-Check
     * @param  bool  $generatedDefault  MySQL: die Vorgabe ist ein Ausdruck (DEFAULT_GENERATED)
     */
    public static function specOf(DatabaseDriver $driver, array $column, bool $isJson = false, bool $generatedDefault = false): ColumnSpec
    {
        $type = trim((string) $column['type']);
        [$key, $length, $precision, $scale, $values] = self::parseType($driver, $type, $isJson) ?? [self::CUSTOM, null, null, null, []];
        [$defaultKind, $defaultValue] = (bool) $column['auto_increment'] ? ['none', null] : self::parseDefault($driver, $column['default'], $generatedDefault);
        $comment = $column['comment'] ?? null;

        return new ColumnSpec(
            name: (string) $column['name'],
            type: $key,
            custom: $key === self::CUSTOM ? $type : null,
            length: $length,
            precision: $precision,
            scale: $scale,
            values: $values,
            nullable: (bool) $column['nullable'],
            defaultKind: $defaultKind,
            defaultValue: $defaultValue,
            autoIncrement: (bool) $column['auto_increment'],
            comment: is_string($comment) && $comment !== '' ? $comment : null,
            original: (string) $column['name'],
        );
    }

    /**
     * @return array{0: string, 1: int|null, 2: int|null, 3: int|null, 4: list<string>}|null
     */
    private static function parseType(DatabaseDriver $driver, string $original, bool $isJson): ?array
    {
        $type = strtolower($original);
        $plain = fn (string $key): array => [$key, null, null, null, []];

        if ($driver === DatabaseDriver::Postgres) {
            return match (true) {
                preg_match('/^character varying\((\d+)\)$/', $type, $match) === 1 => ['string', (int) $match[1], null, null, []],
                preg_match('/^character\((\d+)\)$/', $type, $match) === 1 => ['char', (int) $match[1], null, null, []],
                preg_match('/^numeric\((\d+),(\d+)\)$/', $type, $match) === 1 => ['decimal', null, (int) $match[1], (int) $match[2], []],
                $type === 'double precision' => $plain('double'),
                $type === 'timestamp(0) without time zone' => $plain('timestamp'),
                $type === 'timestamp(0) with time zone' => $plain('timestampTz'),
                $type === 'time(0) without time zone' => $plain('time'),
                $type === 'bytea' => $plain('binary'),
                in_array($type, ['text', 'integer', 'bigint', 'smallint', 'boolean', 'date', 'json', 'jsonb', 'uuid'], true) => $plain(match ($type) {
                    'bigint' => 'bigInteger',
                    'smallint' => 'smallInteger',
                    default => $type,
                }),
                default => null,
            };
        }

        if ($driver === DatabaseDriver::Sqlite) {
            return match ($type) {
                'varchar' => $plain('string'),
                'text', 'integer', 'date', 'datetime', 'time' => $plain(match ($type) {
                    'datetime' => 'dateTime',
                    default => $type,
                }),
                'numeric' => $plain('decimal'),
                'double', 'float' => $plain('double'),
                'tinyint(1)' => $plain('boolean'),
                'blob' => $plain('binary'),
                default => null,
            };
        }

        // MySQL und MariaDB; MariaDB zeigt bei Ganzzahlen die Anzeigebreite («int(11)»).
        if ($isJson && $type === 'longtext') {
            return $plain('json');
        }

        // tinyint(1) ist Laravels Wahrheitswert — vor den Ganzzahlen prüfen.
        if ($type === 'tinyint(1)') {
            return $plain('boolean');
        }

        if (preg_match('/^(tiny|small|big)?int(?:\(\d+\))?( unsigned)?$/', $type, $match, PREG_UNMATCHED_AS_NULL) === 1) {
            $size = $match[1] ?? '';
            $unsigned = ($match[2] ?? null) !== null;
            $key = match ($size) {
                'big' => 'bigInteger',
                'small' => 'smallInteger',
                'tiny' => 'tinyInteger',
                default => 'integer',
            };

            // tinyint unsigned bietet die Liste nicht an — bleibt eigener Typ.
            return $size === 'tiny' && $unsigned ? null : $plain($unsigned ? 'unsigned'.ucfirst($key) : $key);
        }

        return match (true) {
            preg_match('/^varchar\((\d+)\)$/', $type, $match) === 1 => ['string', (int) $match[1], null, null, []],
            preg_match('/^char\((\d+)\)$/', $type, $match) === 1 => ['char', (int) $match[1], null, null, []],
            preg_match('/^decimal\((\d+),(\d+)\)$/', $type, $match) === 1 => ['decimal', null, (int) $match[1], (int) $match[2], []],
            preg_match("/^enum\((.*)\)$/s", $type) === 1 => self::enumValues($original),
            in_array($type, ['text', 'mediumtext', 'longtext', 'double', 'date', 'datetime', 'timestamp', 'time', 'json', 'blob', 'uuid'], true) => $plain(match ($type) {
                'mediumtext' => 'mediumText',
                'longtext' => 'longText',
                'datetime' => 'dateTime',
                'blob' => 'binary',
                default => $type,
            }),
            default => null,
        };
    }

    /**
     * enum('a','b''c') → Auswahl; Werte mit Backslash bleiben eigener Typ (MySQL liest ihn als Escape).
     *
     * @return array{0: string, 1: null, 2: null, 3: null, 4: list<string>}|null
     */
    private static function enumValues(string $type): ?array
    {
        if (str_contains($type, '\\') || preg_match_all("/'((?:[^']|'')*)'/", $type, $matches) === false) {
            return null;
        }

        return ['enum', null, null, null, array_map(fn (string $value): string => str_replace("''", "'", $value), $matches[1])];
    }

    /**
     * Vorgabe aus der Introspektion: Literal → «value», NULL → «none», alles andere ein Ausdruck, wörtlich.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function parseDefault(DatabaseDriver $driver, mixed $default, bool $generated): array
    {
        if ($default === null) {
            return ['none', null];
        }

        $raw = trim((string) $default);

        return match (true) {
            preg_match('/^NULL(::.+)?$/i', $raw) === 1 => ['none', null],
            preg_match("/^'((?:[^']|'')*)'(::[\\w\\s\"\\[\\]().,]+)?$/s", $raw, $match) === 1 => ['value', str_replace("''", "'", $match[1])],
            preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1 => ['value', $raw],
            // MySQL zeigt Literale ohne Anführungszeichen; Ausdrücke erkennt man nur am Vermerk DEFAULT_GENERATED.
            $driver === DatabaseDriver::MySql => $generated ? ['expression', $raw] : ['value', $raw],
            $driver === DatabaseDriver::Postgres && in_array(strtolower($raw), ['true', 'false'], true) => ['value', strtolower($raw)],
            default => ['expression', $raw],
        };
    }
}
