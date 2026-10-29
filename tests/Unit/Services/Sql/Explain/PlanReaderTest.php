<?php

namespace Tests\Unit\Services\Sql\Explain;

use App\Services\Sql\Explain\MySqlPlanReader;
use App\Services\Sql\Explain\PostgresPlanReader;
use App\Services\Sql\Explain\SqlitePlanReader;
use Tests\TestCase;

/**
 * Pläne der vier Treiber als einheitlicher Baum mit Hinweisen (Q-06) — mit Ausgaben, wie die Server sie liefern
 * (MySQL 8.4 in beiden JSON-Formen, MariaDB 10.11 mit ANALYZE, PostgreSQL 18, SQLite 3.5x).
 */
class PlanReaderTest extends TestCase
{
    public function test_mysql_classic_format_becomes_a_tree_with_filesort_temporary_and_unused_index(): void
    {
        $explain = json_decode(<<<'JSON'
            {"query_block": {"select_id": 1, "cost_info": {"query_cost": "1302.41"},
              "ordering_operation": {"using_filesort": false,
                "grouping_operation": {"using_temporary_table": true, "using_filesort": true,
                  "nested_loop": [
                    {"table": {"table_name": "c", "access_type": "ALL", "possible_keys": ["PRIMARY"], "rows_examined_per_scan": 1, "cost_info": {"prefix_cost": "0.35"}}},
                    {"table": {"table_name": "o", "access_type": "ALL", "possible_keys": ["c_id"], "rows_examined_per_scan": 3000, "using_join_buffer": "hash join",
                      "cost_info": {"prefix_cost": "302.51"}, "attached_condition": "(`o`.`total` > 5)"}}
                  ]}}}}
            JSON, true);

        $result = (new MySqlPlanReader)->read($explain, false);

        $this->assertSame(['Query block #1', ['Sort', ['Group', ['Nested loop', ['Table scan', 'Table scan']]]]], $this->shape($result['plan']));
        $this->assertSame(1302.41, $result['plan']['cost']);
        $this->assertSame([['filesort', 'Group'], ['temporary', 'Group'], ['index_unused', 'Table scan']], $this->hints($result));
        $this->assertStringContainsString('«o» (≈ 3’000 Zeilen), obwohl ein Index in Frage käme (c_id)', $result['hints'][2]['message']);
    }

    public function test_mysql_tree_format_flags_scans_sorts_and_temporary_tables_but_not_its_own_temporary_table(): void
    {
        $explain = json_decode(<<<'JSON'
            {"operation": "Table scan on <temporary>", "table_name": "<temporary>", "access_type": "table", "inputs": [
              {"operation": "Aggregate using temporary table", "access_type": "temp_table_aggregate", "inputs": [
                {"operation": "Sort: o.note", "access_type": "sort", "estimated_rows": 3000, "inputs": [
                  {"operation": "Table scan on o", "table_name": "o", "access_type": "table", "estimated_rows": 3000.0, "estimated_total_cost": 302.0}
                ]}
              ]}
            ]}
            JSON, true);

        $result = (new MySqlPlanReader)->read($explain, false);

        $this->assertSame(['Table scan on <temporary>', ['Aggregate using temporary table', ['Sort: o.note', ['Table scan on o']]]], $this->shape($result['plan']));
        $this->assertSame([['temporary', 'Aggregate using temporary table'], ['filesort', 'Sort: o.note'], ['full_scan', 'Table scan on o']], $this->hints($result));
    }

    public function test_mariadb_analyze_names_a_bad_estimate_only_where_it_starts(): void
    {
        $explain = json_decode(<<<'JSON'
            {"query_optimization": {"r_total_time_ms": 0.05}, "query_block": {"select_id": 1, "r_loops": 1, "r_total_time_ms": 12.5, "nested_loop": [
              {"read_sorted_file": {"r_rows": 5000, "filesort": {"sort_key": "o.note", "r_loops": 1, "r_total_time_ms": 3.2,
                "table": {"table_name": "o", "access_type": "ALL", "r_loops": 1, "rows": 10, "r_rows": 5000, "r_table_time_ms": 2.1, "attached_condition": "o.total = 5"}}}}
            ]}}
            JSON, true);

        $result = (new MySqlPlanReader)->read($explain, true);
        $scan = $result['plan']['children'][0]['children'][0]['children'][0];

        $this->assertSame(['Table scan', 'o', 10.0, 5000.0, 2.1], [$scan['operation'], $scan['object'], $scan['rows'], $scan['actualRows'], $scan['time']]);
        $this->assertSame([['filesort', 'Filesort'], ['estimate', 'Table scan']], $this->hints($result));
        $this->assertStringContainsString('erwartet ≈ 10, tatsächlich 5’000 Zeilen', $result['hints'][1]['message']);
    }

    public function test_postgres_plan_uses_the_table_statistics_for_full_scans_and_flags_sorts_on_disk(): void
    {
        $explain = json_decode(<<<'JSON'
            [{"Plan": {"Node Type": "Sort", "Total Cost": 117.18, "Plan Rows": 200, "Sort Key": ["o.note DESC"], "Sort Method": "external merge", "Sort Space Used": 4096, "Sort Space Type": "Disk",
              "Plans": [{"Node Type": "Seq Scan", "Relation Name": "orders", "Schema": "shop", "Alias": "o", "Total Cost": 54.5, "Plan Rows": 12, "Filter": "(o.total > 5)"},
                        {"Node Type": "Index Scan", "Relation Name": "customers", "Schema": "shop", "Alias": "customers", "Index Name": "customers_pkey", "Plan Rows": 1}]}}]
            JSON, true);
        $asked = [];

        $result = (new PostgresPlanReader(function (?string $schema, string $table) use (&$asked): ?float {
            $asked[] = $schema.'.'.$table;

            return 50000.0;
        }))->read($explain, false);

        $this->assertSame(['Sort', ['Seq Scan', 'Index Scan']], $this->shape($result['plan']));
        $this->assertSame('orders o', $result['plan']['children'][0]['object']);
        $this->assertContains('Filter: (o.total > 5)', $result['plan']['children'][0]['details']);
        $this->assertSame(['shop.orders'], $asked);
        $this->assertSame([['filesort', 'Sort'], ['full_scan', 'Seq Scan']], $this->hints($result));
        $this->assertStringContainsString('(≈ 50’000 Zeilen)', $result['hints'][1]['message']);
    }

    public function test_sqlite_rows_become_a_tree_under_one_root(): void
    {
        $result = (new SqlitePlanReader)->read([
            ['id' => 2, 'parent' => 0, 'detail' => 'SEARCH o USING INTEGER PRIMARY KEY (rowid=?)'],
            ['id' => 5, 'parent' => 0, 'detail' => 'LIST SUBQUERY 1'],
            ['id' => 7, 'parent' => 5, 'detail' => 'SCAN o'],
            ['id' => 20, 'parent' => 0, 'detail' => 'USE TEMP B-TREE FOR ORDER BY'],
            ['id' => 21, 'parent' => 0, 'detail' => 'USE TEMP B-TREE FOR DISTINCT'],
        ]);

        $this->assertSame(['QUERY PLAN', ['SEARCH', 'LIST SUBQUERY', ['SCAN'], 'USE TEMP B-TREE', 'USE TEMP B-TREE']], $this->shape($result['plan']));
        $this->assertSame([['full_scan', 'SCAN'], ['filesort', 'USE TEMP B-TREE'], ['temporary', 'USE TEMP B-TREE']], $this->hints($result));
    }

    /**
     * Operationen als verschachtelte Liste: Name, dann die Kinder.
     *
     * @param  array<string, mixed>  $node
     * @return list<mixed>
     */
    private function shape(array $node): array
    {
        $shape = [$node['operation']];
        $children = [];

        foreach ($node['children'] as $child) {
            $nested = $this->shape($child);
            $children = [...$children, ...(count($nested) === 1 ? $nested : [$nested[0], $nested[1]])];
        }

        if ($children !== []) {
            $shape[] = $children;
        }

        return $shape;
    }

    /**
     * Art und Operation des Knotens je Hinweis.
     *
     * @param  array{plan: array<string, mixed>, hints: list<array{kind: string, node: int, message: string}>}  $result
     * @return list<array{0: string, 1: string}>
     */
    private function hints(array $result): array
    {
        $operations = [];
        $collect = function (array $node) use (&$collect, &$operations): void {
            $operations[$node['id']] = $node['operation'];
            array_map($collect, $node['children']);
        };
        $collect($result['plan']);

        return array_map(fn (array $hint): array => [$hint['kind'], $operations[$hint['node']] ?? '?'], $result['hints']);
    }
}
