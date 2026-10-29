<?php

namespace Tests\Unit\Services\Schema;

use App\Enums\DatabaseDriver;
use App\Services\Schema\ColumnTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bestehende Spalten als Eintrag des Formulars (S-03): ein Typ aus der Liste nur, wenn Laravel daraus wieder genau
 * denselben Typ erzeugt — sonst bleibt er wörtlich als eigener Typ. Vorgaben: Literale werden zu Werten, alles andere
 * bleibt ein Ausdruck, wie die Datenbank ihn meldet.
 */
class ColumnTypesTest extends TestCase
{
    /**
     * @return array<string, array{0: DatabaseDriver, 1: string, 2: array{0: string, 1: string|null, 2: int|null, 3: int|null, 4: int|null, 5: list<string>}}>
     */
    public static function types(): array
    {
        return [
            'MariaDB int(11)' => [DatabaseDriver::MariaDb, 'int(11)', ['integer', null, null, null, null, []]],
            'MariaDB bigint(20) unsigned' => [DatabaseDriver::MariaDb, 'bigint(20) unsigned', ['unsignedBigInteger', null, null, null, null, []]],
            'tinyint(1) ist Wahrheitswert' => [DatabaseDriver::MySql, 'tinyint(1)', ['boolean', null, null, null, null, []]],
            'MariaDB tinyint(4)' => [DatabaseDriver::MariaDb, 'tinyint(4)', ['tinyInteger', null, null, null, null, []]],
            'tinyint unsigned bleibt eigener Typ' => [DatabaseDriver::MySql, 'tinyint unsigned', ['custom', 'tinyint unsigned', null, null, null, []]],
            'varchar mit Länge' => [DatabaseDriver::MySql, 'varchar(40)', ['string', null, 40, null, null, []]],
            'decimal' => [DatabaseDriver::MySql, 'decimal(10,2)', ['decimal', null, null, 10, 2, []]],
            'enum mit Anführungszeichen' => [DatabaseDriver::MySql, "enum('Offen','o''k')", ['enum', null, null, null, null, ['Offen', "o'k"]]],
            'enum mit Backslash bleibt wörtlich' => [DatabaseDriver::MySql, "enum('a\\\\b')", ['custom', "enum('a\\\\b')", null, null, null, []]],
            'datetime(6) bleibt wörtlich' => [DatabaseDriver::MySql, 'datetime(6)', ['custom', 'datetime(6)', null, null, null, []]],
            'PostgreSQL character varying' => [DatabaseDriver::Postgres, 'character varying(40)', ['string', null, 40, null, null, []]],
            'PostgreSQL numeric' => [DatabaseDriver::Postgres, 'numeric(12,4)', ['decimal', null, null, 12, 4, []]],
            'PostgreSQL timestamp(0)' => [DatabaseDriver::Postgres, 'timestamp(0) without time zone', ['timestamp', null, null, null, null, []]],
            'PostgreSQL timestamp ohne Genauigkeit' => [DatabaseDriver::Postgres, 'timestamp without time zone', ['custom', 'timestamp without time zone', null, null, null, []]],
            'PostgreSQL Array' => [DatabaseDriver::Postgres, 'integer[]', ['custom', 'integer[]', null, null, null, []]],
            'SQLite in Grossbuchstaben' => [DatabaseDriver::Sqlite, 'INTEGER', ['integer', null, null, null, null, []]],
            'SQLite varchar ohne Länge' => [DatabaseDriver::Sqlite, 'varchar', ['string', null, null, null, null, []]],
        ];
    }

    /**
     * @param  array{0: string, 1: string|null, 2: int|null, 3: int|null, 4: int|null, 5: list<string>}  $expected
     */
    #[DataProvider('types')]
    public function test_maps_existing_types_only_where_laravel_produces_them_again(DatabaseDriver $driver, string $type, array $expected): void
    {
        $spec = ColumnTypes::specOf($driver, $this->column($type));

        $this->assertSame($expected, [$spec->type, $spec->custom, $spec->length, $spec->precision, $spec->scale, $spec->values]);
    }

    /**
     * @return array<string, array{0: DatabaseDriver, 1: string|null, 2: bool, 3: string, 4: string|null}>
     */
    public static function defaults(): array
    {
        return [
            'keine' => [DatabaseDriver::MySql, null, false, 'none', null],
            'MariaDB NULL als Text' => [DatabaseDriver::MariaDb, 'NULL', false, 'none', null],
            'MariaDB Literal' => [DatabaseDriver::MariaDb, "'o''k'", false, 'value', "o'k"],
            'MariaDB Ausdruck' => [DatabaseDriver::MariaDb, 'current_timestamp()', false, 'expression', 'current_timestamp()'],
            'MySQL Text ohne Anführungszeichen' => [DatabaseDriver::MySql, 'CURRENT_TIMESTAMP', false, 'value', 'CURRENT_TIMESTAMP'],
            'MySQL Ausdruck (DEFAULT_GENERATED)' => [DatabaseDriver::MySql, 'CURRENT_TIMESTAMP', true, 'expression', 'CURRENT_TIMESTAMP'],
            'PostgreSQL mit Typumwandlung' => [DatabaseDriver::Postgres, "'a''b'::character varying", false, 'value', "a'b"],
            'PostgreSQL Zahl' => [DatabaseDriver::Postgres, '-1.5', false, 'value', '-1.5'],
            'PostgreSQL Wahrheitswert' => [DatabaseDriver::Postgres, 'true', false, 'value', 'true'],
            'PostgreSQL Funktion' => [DatabaseDriver::Postgres, 'now()', false, 'expression', 'now()'],
        ];
    }

    #[DataProvider('defaults')]
    public function test_reads_defaults_as_value_or_expression(DatabaseDriver $driver, ?string $default, bool $generated, string $kind, ?string $value): void
    {
        $spec = ColumnTypes::specOf($driver, [...$this->column('varchar(20)'), 'default' => $default], generatedDefault: $generated);

        $this->assertSame([$kind, $value], [$spec->defaultKind, $spec->defaultValue]);
    }

    public function test_auto_increment_has_no_default(): void
    {
        $spec = ColumnTypes::specOf(DatabaseDriver::Postgres, [...$this->column('bigint'), 'default' => "nextval('orders_id_seq'::regclass)", 'auto_increment' => true]);

        $this->assertSame(['bigInteger', true, 'none'], [$spec->type, $spec->autoIncrement, $spec->defaultKind]);
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $type): array
    {
        return ['name' => 'spalte', 'type' => $type, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null];
    }
}
