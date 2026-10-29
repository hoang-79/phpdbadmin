<?php

namespace Tests\Unit\Services\Sql;

use App\Enums\DatabaseDriver;
use App\Services\Sql\SingleTableSelect;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Erkennen, ob eine Abfrage genau eine Tabelle liest (Q-05): eng gefasst, im Zweifel «nur lesen» mit Grund.
 */
class SingleTableSelectTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: DatabaseDriver, 2: string|null, 3: string, 4: string|null, 5: list<string>}>
     */
    public static function accepted(): array
    {
        $mysql = DatabaseDriver::MySql;
        $pgsql = DatabaseDriver::Postgres;
        $sqlite = DatabaseDriver::Sqlite;

        return [
            'Stern' => ['select * from customers', $mysql, null, 'customers', null, ['star']],
            'Spalten, Alias, Bedingung, Reihenfolge, Grenze' => ['SELECT c.id, c.name AS n FROM shop.customers c WHERE c.id > 0 ORDER BY c.id LIMIT 10, 5;', $mysql, 'shop', 'customers', 'c', ['column:id', 'column:name>n']],
            'Unterabfrage in WHERE' => ["select id, email from customers where id in (select customer_id from orders where note = 'from x join y')", $pgsql, null, 'customers', null, ['column:id', 'column:email']],
            'Ausdrücke bleiben Ausdrücke' => ['select id, upper(name), price * 2 total, current_user, null from t', $pgsql, null, 't', null, ['column:id', 'expression', 'expression', 'expression', 'expression']],
            'gequotet je Dialekt' => ['select "Id", "Na""me" from "Kunden" as "K"', $pgsql, null, 'Kunden', 'K', ['column:Id', 'column:Na"me']],
            'Backticks und Kommentare' => ["select `id`, /* x */ `name` -- y\nfrom `kunden` # z\nwhere 1", $mysql, null, 'kunden', null, ['column:id', 'column:name']],
            'eckige Klammern in SQLite' => ['select [id], t.* from [main].[t]', $sqlite, 'main', 't', null, ['column:id', 'star']],
            'Zeichenkette als Alias in MySQL' => ["select name 'Name' from t", $mysql, null, 't', null, ['column:name>Name']],
            'FETCH FIRST' => ['select id from t order by id offset 5 rows fetch first 10 rows only', $pgsql, null, 't', null, ['column:id']],
            'Dollar-Zeichenkette' => ['select id, $$ from x join y $$ as s from t', $pgsql, null, 't', null, ['column:id', 'expression']],
        ];
    }

    /**
     * @param  list<string>  $items
     */
    #[DataProvider('accepted')]
    public function test_recognises_a_query_of_one_table(string $sql, DatabaseDriver $driver, ?string $schema, string $table, ?string $alias, array $items): void
    {
        $parsed = SingleTableSelect::parse($sql, $driver);

        $this->assertInstanceOf(SingleTableSelect::class, $parsed);
        $this->assertSame([$schema, $table, $alias], [$parsed->schema, $parsed->table, $parsed->alias]);
        $this->assertSame($items, array_map(fn (array $item): string => match ($item['kind']) {
            'column' => 'column:'.$item['name'].($item['alias'] !== null ? '>'.$item['alias'] : ''),
            default => $item['kind'],
        }, $parsed->items));
    }

    /**
     * @return array<string, array{0: string, 1: DatabaseDriver, 2: string}>
     */
    public static function refused(): array
    {
        $mysql = DatabaseDriver::MySql;
        $pgsql = DatabaseDriver::Postgres;

        return [
            'kein SELECT' => ['update t set a = 1', $mysql, SingleTableSelect::NOT_SELECT],
            'WITH' => ['with x as (select 1) select * from x', $pgsql, SingleTableSelect::NOT_SELECT],
            'ohne Tabelle' => ['select 1', $mysql, SingleTableSelect::NO_TABLE],
            'Join' => ['select c.id from customers c join orders o on o.customer_id = c.id', $mysql, SingleTableSelect::SEVERAL_TABLES],
            'LEFT JOIN' => ['select * from a left outer join b using (id)', $pgsql, SingleTableSelect::SEVERAL_TABLES],
            'zwei Tabellen mit Komma' => ['select * from a, b', $mysql, SingleTableSelect::SEVERAL_TABLES],
            'GROUP BY' => ['select status, count(*) from t group by status', $mysql, SingleTableSelect::GROUPED],
            'DISTINCT' => ['select distinct id from t', $pgsql, SingleTableSelect::DISTINCT],
            'UNION' => ['select id from a union select id from b', $mysql, SingleTableSelect::SET_OPERATION],
            'Unterabfrage als Quelle' => ['select * from (select * from t) x', $pgsql, SingleTableSelect::UNSUPPORTED],
            'ausführbarer Kommentar' => ['select /*!50000 id, */ name from t', $mysql, SingleTableSelect::UNSUPPORTED],
            'Index-Hinweis' => ['select * from t use index (i)', $mysql, SingleTableSelect::UNSUPPORTED],
            'ONLY' => ['select * from only t', $pgsql, SingleTableSelect::UNSUPPORTED],
            'FOR UPDATE' => ['select * from t for update', $pgsql, SingleTableSelect::UNSUPPORTED],
            'SELECT INTO' => ['select * into neu from t', $pgsql, SingleTableSelect::UNSUPPORTED],
            'offene Zeichenkette' => ["select 'abc from t", $mysql, SingleTableSelect::UNSUPPORTED],
            'drei Namensteile' => ['select * from db.schema.t', $pgsql, SingleTableSelect::UNSUPPORTED],
        ];
    }

    #[DataProvider('refused')]
    public function test_refuses_everything_else_with_a_reason(string $sql, DatabaseDriver $driver, string $reason): void
    {
        $this->assertSame($reason, SingleTableSelect::parse($sql, $driver));
    }
}
