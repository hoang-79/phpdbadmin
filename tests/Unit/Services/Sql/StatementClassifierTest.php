<?php

namespace Tests\Unit\Services\Sql;

use App\Services\Sql\StatementClassifier;
use App\Services\Sql\StatementKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Einordnung im SQL-Editor (Q-03, Q-07): lesend oder schreibend, und gefährlich mit Varianten — Kommentare, Gross- und
 * Kleinschreibung, WHERE nur in Unterabfragen oder Zeichenketten.
 */
class StatementClassifierTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: StatementKind}>
     */
    public static function kinds(): array
    {
        return [
            'select' => ['select * from t', StatementKind::Read],
            'Kommentar davor' => ["-- Bericht\n/* x */ SELECT 1", StatementKind::Read],
            'Klammer davor' => ['(select 1) union (select 2)', StatementKind::Read],
            'show' => ['SHOW TABLES', StatementKind::Read],
            'explain' => ['explain select 1', StatementKind::Read],
            'explain analyze delete' => ['explain analyze delete from t', StatementKind::Write],
            'with lesend' => ['with x as (select 1) select * from x', StatementKind::Read],
            'with löschend' => ['with x as (delete from t returning *) select * from x', StatementKind::Write],
            'select into' => ['select * into neu from t', StatementKind::Write],
            'select mit into in Zeichenkette' => ["select 'into' from t", StatementKind::Read],
            'pragma lesend' => ['pragma table_info(t)', StatementKind::Read],
            'pragma schreibend' => ['PRAGMA foreign_keys = off', StatementKind::Write],
            'insert' => ['insert into t values (1)', StatementKind::Write],
            'set' => ['set @a = 1', StatementKind::Write],
            'create' => ['CREATE TABLE x (id int)', StatementKind::Write],
        ];
    }

    #[DataProvider('kinds')]
    public function test_kind(string $sql, StatementKind $expected): void
    {
        $this->assertSame($expected, (new StatementClassifier)->kind($sql));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function dangers(): array
    {
        return [
            'drop' => ['drop table t', true],
            'Drop mit Kommentar' => ["/* aufräumen */\n  DrOp TABLE IF EXISTS t", true],
            'truncate' => ['TRUNCATE t', true],
            'delete ohne where' => ['delete from t', true],
            'delete mit where' => ['DELETE FROM t WHERE id = 1', false],
            'delete mit where nur in Unterabfrage' => ['delete from t where id in (select id from u where x = 1)', false],
            'update ohne where, where in Unterabfrage' => ['update t set a = (select b from u where u.id = 1)', true],
            'update ohne where, where in Zeichenkette' => ["update t set note = 'where am I'", true],
            'update ohne where, where im Kommentar' => ['update t set a = 1 -- where id = 1', true],
            'update mit where' => ["update t set a = 1\n where id = 2", false],
            'alter drop column' => ['alter table t drop column a', true],
            'alter add column' => ['alter table t add column a int', false],
            'select' => ['select * from t', false],
        ];
    }

    #[DataProvider('dangers')]
    public function test_danger(string $sql, bool $dangerous): void
    {
        $this->assertSame($dangerous, (new StatementClassifier)->danger($sql) !== null);
    }
}
