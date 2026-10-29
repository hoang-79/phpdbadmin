<?php

namespace Tests\Unit\Services\Shape;

use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use App\Services\Shape\DefaultValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vorgaben über Treiber hinweg vergleichbar (S-08): jeder Treiber meldet sie anders — die Fälle stammen aus Laravels
 * Introspektion gegen die Herd-Dienste.
 */
class DefaultValueTest extends TestCase
{
    /**
     * @return array<string, array{0: DatabaseDriver, 1: string|null, 2: string|null}>
     */
    public static function reported(): array
    {
        return [
            'MySQL Text ohne Anführungszeichen' => [DatabaseDriver::MySql, 'aktiv', "'aktiv'"],
            'MySQL leerer Text' => [DatabaseDriver::MySql, '', "''"],
            'MySQL Zahl' => [DatabaseDriver::MySql, '0.00', "'0.00'"],
            'MySQL Zeitpunkt' => [DatabaseDriver::MySql, 'CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP'],
            'MySQL Ausdruck' => [DatabaseDriver::MySql, 'uuid()', 'uuid()'],
            'MariaDB Text in Anführungszeichen' => [DatabaseDriver::MariaDb, "'aktiv'", "'aktiv'"],
            'MariaDB ohne Vorgabe als Text NULL' => [DatabaseDriver::MariaDb, 'NULL', null],
            'MariaDB Zeitpunkt' => [DatabaseDriver::MariaDb, 'current_timestamp()', 'CURRENT_TIMESTAMP'],
            'PostgreSQL Text mit Umwandlung' => [DatabaseDriver::Postgres, "'aktiv'::character varying", "'aktiv'"],
            'PostgreSQL Zahl mit Umwandlung' => [DatabaseDriver::Postgres, "'0'::numeric", "'0'"],
            'PostgreSQL negative Zahl' => [DatabaseDriver::Postgres, '(-1)', "'-1'"],
            'PostgreSQL Wahrheitswert' => [DatabaseDriver::Postgres, 'true', "'1'"],
            'PostgreSQL NULL mit Umwandlung' => [DatabaseDriver::Postgres, 'NULL::text', null],
            'PostgreSQL now()' => [DatabaseDriver::Postgres, 'now()', 'CURRENT_TIMESTAMP'],
            'SQLite Zahl in Anführungszeichen' => [DatabaseDriver::Sqlite, "'0'", "'0'"],
            'SQLite Ausdruck in Klammern' => [DatabaseDriver::Sqlite, "(datetime('now'))", "datetime('now')"],
            'SQLite Anführungszeichen im Text' => [DatabaseDriver::Sqlite, "'O''Brien'", "'O''Brien'"],
            'keine Vorgabe' => [DatabaseDriver::Sqlite, null, null],
        ];
    }

    #[DataProvider('reported')]
    public function test_reads_what_each_driver_reports(DatabaseDriver $driver, ?string $raw, ?string $display): void
    {
        $this->assertSame($display, DefaultValue::fromIntrospection($raw, $driver)?->display());
    }

    public function test_autoincrement_has_no_default(): void
    {
        $this->assertNull(DefaultValue::fromIntrospection("nextval('kunden_id_seq'::regclass)", DatabaseDriver::Postgres, autoIncrement: true));
    }

    /**
     * @return array<string, array{0: DefaultValue|null, 1: DefaultValue|null, 2: ColumnKind, 3: bool}>
     */
    public static function pairs(): array
    {
        return [
            'Zahl nach Wert' => [DefaultValue::literal('0'), DefaultValue::literal('0.00'), ColumnKind::Number, true],
            'Wahrheitswert nach Bedeutung' => [DefaultValue::literal('1'), DefaultValue::literal('true'), ColumnKind::Boolean, true],
            'Wahrheitswert verschieden' => [DefaultValue::literal('1'), DefaultValue::literal('0'), ColumnKind::Boolean, false],
            'Text genau' => [DefaultValue::literal('aktiv'), DefaultValue::literal('Aktiv'), ColumnKind::Text, false],
            'Ausdruck ohne Gross- und Kleinschreibung' => [DefaultValue::expression('current_timestamp'), DefaultValue::expression('CURRENT_TIMESTAMP()'), ColumnKind::DateTime, true],
            'Wert gegen Ausdruck' => [DefaultValue::literal('CURRENT_TIMESTAMP'), DefaultValue::expression('CURRENT_TIMESTAMP'), ColumnKind::Text, false],
            'eine Seite ohne Vorgabe' => [DefaultValue::literal('0'), null, ColumnKind::Number, false],
            'beide ohne Vorgabe' => [null, null, ColumnKind::Number, true],
        ];
    }

    #[DataProvider('pairs')]
    public function test_compares_by_meaning(?DefaultValue $a, ?DefaultValue $b, ColumnKind $kind, bool $same): void
    {
        $this->assertSame($same, DefaultValue::same($a, $b, $kind));
    }

    public function test_only_current_time_expressions_are_portable(): void
    {
        $this->assertTrue(DefaultValue::expression('CURRENT_TIMESTAMP(6)')->isPortable());
        $this->assertTrue(DefaultValue::literal('uuid()')->isPortable());
        $this->assertFalse(DefaultValue::expression('gen_random_uuid()')->isPortable());
    }
}
