<?php

namespace Tests\Unit\Services\Objects;

use App\Enums\DatabaseDriver;
use App\Enums\ObjectKind;
use App\Services\Objects\DefinitionHead;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kopf einer selbst geschriebenen Definition (S-06): Verb, Art, Schema und Name — mit DEFINER, Anführungszeichen,
 * Kommentaren und den Faltregeln je Treiber. Was hier falsch gelesen würde, könnte ein Objekt im falschen Schema
 * ändern.
 */
class DefinitionHeadTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: DatabaseDriver, 2: array{verb: string, orReplace: bool, keyword: string, schema: string|null, name: string, table: string|null}}>
     */
    public static function heads(): array
    {
        return [
            'MySQL mit DEFINER' => ['CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `shop`.`offen` AS select 1', DatabaseDriver::MySql,
                ['verb' => 'create', 'orReplace' => false, 'keyword' => 'VIEW', 'schema' => 'shop', 'name' => 'offen', 'table' => null]],
            'MySQL DEFINER in einfachen Anführungszeichen' => ["create or replace definer = 'app'@'%' function netto(x int) returns int return x", DatabaseDriver::MySql,
                ['verb' => 'create', 'orReplace' => true, 'keyword' => 'FUNCTION', 'schema' => null, 'name' => 'netto', 'table' => null]],
            'MySQL DEFINER CURRENT_USER()' => ['create definer = current_user() procedure p() begin end', DatabaseDriver::MariaDb,
                ['verb' => 'create', 'orReplace' => false, 'keyword' => 'PROCEDURE', 'schema' => null, 'name' => 'p', 'table' => null]],
            'Kommentar davor' => ["-- neu\n/* Trigger */ CREATE TRIGGER t_a BEFORE INSERT ON orders FOR EACH ROW SET NEW.a = 1", DatabaseDriver::MySql,
                ['verb' => 'create', 'orReplace' => false, 'keyword' => 'TRIGGER', 'schema' => null, 'name' => 't_a', 'table' => 'orders']],
            'Trigger mit Spalten vor ON' => ['create trigger t_b after update of a, b on "shop"."orders" for each row execute function f()', DatabaseDriver::Postgres,
                ['verb' => 'create', 'orReplace' => false, 'keyword' => 'TRIGGER', 'schema' => null, 'name' => 't_b', 'table' => 'orders']],
            'PostgreSQL faltet ohne Anführungszeichen' => ['CREATE OR REPLACE FUNCTION Public.Netto(x numeric) RETURNS numeric AS $$ select x $$ LANGUAGE sql', DatabaseDriver::Postgres,
                ['verb' => 'create', 'orReplace' => true, 'keyword' => 'FUNCTION', 'schema' => 'public', 'name' => 'netto', 'table' => null]],
            'PostgreSQL mit Anführungszeichen' => ['create view "Offene Posten" as select 1', DatabaseDriver::Postgres,
                ['verb' => 'create', 'orReplace' => false, 'keyword' => 'VIEW', 'schema' => null, 'name' => 'Offene Posten', 'table' => null]],
            'Domäne' => ['create domain betrag as numeric check (value > 0)', DatabaseDriver::Postgres,
                ['verb' => 'create', 'orReplace' => false, 'keyword' => 'DOMAIN', 'schema' => null, 'name' => 'betrag', 'table' => null]],
            'ALTER mit IF EXISTS' => ['alter sequence if exists public.nummern increment by 2', DatabaseDriver::Postgres,
                ['verb' => 'alter', 'orReplace' => false, 'keyword' => 'SEQUENCE', 'schema' => 'public', 'name' => 'nummern', 'table' => null]],
            'SQLite mit eckigen Klammern' => ['create view if not exists [aux].[liste] as select 1', DatabaseDriver::Sqlite,
                ['verb' => 'create', 'orReplace' => false, 'keyword' => 'VIEW', 'schema' => 'aux', 'name' => 'liste', 'table' => null]],
            'Anführungszeichen im Namen' => ['create view `a``b` as select 1', DatabaseDriver::MySql,
                ['verb' => 'create', 'orReplace' => false, 'keyword' => 'VIEW', 'schema' => null, 'name' => 'a`b', 'table' => null]],
        ];
    }

    /**
     * @param  array{verb: string, orReplace: bool, keyword: string, schema: string|null, name: string, table: string|null}  $expected
     */
    #[DataProvider('heads')]
    public function test_reads_verb_kind_schema_and_name(string $sql, DatabaseDriver $driver, array $expected): void
    {
        $head = DefinitionHead::parse($sql, $driver);

        $this->assertNotNull($head);
        $this->assertSame($expected, [
            'verb' => $head->verb,
            'orReplace' => $head->orReplace,
            'keyword' => $head->keyword,
            'schema' => $head->schema,
            'name' => $head->name,
            'table' => $head->table,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: DatabaseDriver}>
     */
    public static function notDefinitions(): array
    {
        return [
            'Tabelle' => ['create table t (id int)', DatabaseDriver::MySql],
            'materialisierte Sicht' => ['create materialized view m as select 1', DatabaseDriver::Postgres],
            'DROP' => ['drop view v', DatabaseDriver::Sqlite],
            'SELECT' => ['select 1', DatabaseDriver::Postgres],
            'Name aus drei Teilen' => ['create view a.b.c as select 1', DatabaseDriver::Postgres],
            'ohne Namen' => ['create view', DatabaseDriver::MySql],
        ];
    }

    #[DataProvider('notDefinitions')]
    public function test_other_statements_have_no_head(string $sql, DatabaseDriver $driver): void
    {
        $this->assertNull(DefinitionHead::parse($sql, $driver));
    }

    public function test_temporary_objects_are_recognised(): void
    {
        $this->assertTrue(DefinitionHead::parse('create temp view v as select 1', DatabaseDriver::Sqlite)?->temporary);
        $this->assertTrue(DefinitionHead::parse('create temporary sequence s', DatabaseDriver::Postgres)?->temporary);
    }

    public function test_names_follow_the_rules_of_the_driver(): void
    {
        $postgres = DefinitionHead::parse('create view "Liste" as select 1', DatabaseDriver::Postgres);
        $mysql = DefinitionHead::parse('create view Liste as select 1', DatabaseDriver::MySql);

        $this->assertTrue($postgres?->names('Liste'));
        $this->assertFalse($postgres?->names('liste'));
        $this->assertTrue($mysql?->names('liste'));
        $this->assertTrue($mysql?->isIn('shop'));
        $this->assertTrue($postgres?->isKind(ObjectKind::View));
        $this->assertTrue(DefinitionHead::parse('create domain d as int', DatabaseDriver::Postgres)?->isKind(ObjectKind::Type));
    }

    public function test_definer_can_be_removed_and_or_replace_added(): void
    {
        $ddl = "CREATE DEFINER=`root`@`localhost` TRIGGER `t` BEFORE INSERT ON `o` FOR EACH ROW set new.note = 'DEFINER=`x`@`y`'";

        $own = DefinitionHead::withoutDefiner($ddl, DatabaseDriver::MySql);

        $this->assertSame("CREATE TRIGGER `t` BEFORE INSERT ON `o` FOR EACH ROW set new.note = 'DEFINER=`x`@`y`'", $own);
        $this->assertSame("CREATE OR REPLACE TRIGGER `t` BEFORE INSERT ON `o` FOR EACH ROW set new.note = 'DEFINER=`x`@`y`'", DefinitionHead::withOrReplace($own, DatabaseDriver::MariaDb));
        $this->assertSame('CREATE OR REPLACE VIEW v AS select 1', DefinitionHead::withOrReplace('CREATE OR REPLACE VIEW v AS select 1', DatabaseDriver::Postgres));
    }
}
