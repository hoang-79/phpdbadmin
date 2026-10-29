<?php

namespace App\Services\Sql\Explain;

/**
 * Plan von MySQL und MariaDB aus `EXPLAIN FORMAT=JSON` bzw. `ANALYZE FORMAT=JSON` — in zwei Formen:
 *
 * - **MySQL ab 8.3** mit `explain_json_format_version = 2`: ein Baum aus Operationen («Table scan on orders»,
 *   «Sort: …») mit «inputs» darunter, mit ANALYZE auch tatsächliche Zeilen und Zeit.
 * - **MySQL bis 8.2 und MariaDB**: `query_block` mit verschachtelten Teilen (`nested_loop`, `ordering_operation`,
 *   `filesort`, `temporary_table` …) und Tabellen mit Zugriffsart (`ALL`, `ref`, `range` …); MariaDB mit ANALYZE
 *   zusätzlich `r_rows` und `r_total_time_ms`.
 *
 * Unbekannte Teile werden nicht verworfen, sondern als Knoten mit ihrem Namen gezeigt.
 *
 * @phpstan-import-type PlanNode from PlanReader
 * @phpstan-import-type PlanHint from PlanReader
 */
final class MySqlPlanReader extends PlanReader
{
    /** Zugriffsart einer Tabelle (Format bis 8.2, MariaDB) → Operation in den Worten von MySQL. */
    private const array ACCESS = [
        'ALL' => 'Table scan',
        'index' => 'Index scan',
        'range' => 'Index range scan',
        'ref' => 'Index lookup',
        'eq_ref' => 'Single-row index lookup',
        'ref_or_null' => 'Index lookup or null',
        'const' => 'Constant row',
        'system' => 'Constant row',
        'fulltext' => 'Fulltext index search',
        'index_merge' => 'Index merge',
        'unique_subquery' => 'Index lookup (subquery)',
        'index_subquery' => 'Index lookup (subquery)',
        'hash' => 'Hash lookup',
    ];

    /** Verschachtelte Teile (Format bis 8.2, MariaDB) → Operation. */
    private const array PARTS = [
        'nested_loop' => 'Nested loop',
        'ordering_operation' => 'Sort',
        'grouping_operation' => 'Group',
        'duplicates_removal' => 'Duplicates removal',
        'windowing' => 'Window',
        'filesort' => 'Filesort',
        'temporary_table' => 'Temporary table',
        'read_sorted_file' => 'Read sorted file',
        'union_result' => 'Union',
        'materialized_from_subquery' => 'Materialize',
        'subqueries' => 'Subqueries',
        'attached_subqueries' => 'Subqueries',
        'optimized_away_subqueries' => 'Subqueries (optimized away)',
        'having_subqueries' => 'Subqueries (HAVING)',
        'select_list_subqueries' => 'Subqueries (SELECT list)',
        'query_specifications' => 'Queries',
        'block-nl-join' => 'Block nested loop join',
        'buffer_type' => 'Join buffer',
    ];

    /** Angaben, die als Details erscheinen. */
    private const array DETAILS = [
        'condition', 'attached_condition', 'key', 'index_name', 'possible_keys', 'ref', 'used_key_parts', 'join_type',
        'join_algorithm', 'hash_condition', 'sort_fields', 'sort_key', 'ranges', 'lookup_condition', 'filtered', 'r_filtered',
        'using_join_buffer', 'using_index', 'using_filesort', 'using_temporary_table', 'r_sort_mode', 'buffer_type',
    ];

    /**
     * @param  array<mixed>  $explain
     * @return array{plan: PlanNode, hints: list<PlanHint>}
     */
    public function read(array $explain, bool $analyzed): array
    {
        if (isset($explain['query_block']) && is_array($explain['query_block'])) {
            return $this->result($this->part('query_block', $explain['query_block'], $analyzed));
        }

        return $this->result($this->operation($explain, $analyzed));
    }

    /**
     * MySQL ab 8.3: Operation mit «inputs».
     *
     * @param  array<mixed>  $plan
     * @return PlanNode
     */
    private function operation(array $plan, bool $analyzed): array
    {
        $id = $this->nextId();
        $operation = self::text($plan['operation'] ?? null) ?? '?';
        $table = self::text($plan['table_name'] ?? null);
        $access = self::text($plan['access_type'] ?? null);
        $rows = self::float($plan['estimated_rows'] ?? null);
        $actual = $analyzed ? self::float($plan['actual_rows'] ?? null) : null;

        if ($access === 'table' && $table !== null && ! str_starts_with($table, '<') && ($rows ?? 0) >= self::LARGE_TABLE) {
            $this->fullScan($id, $table, $rows);
        }

        if ($access === 'sort') {
            $this->filesort($id);
        }

        if (in_array($access, ['temp_table_aggregate', 'materialize'], true) || stripos($operation, 'temporary') !== false && $access !== 'table') {
            $this->temporary($id);
        }

        $node = $this->node(
            $operation,
            $table,
            $rows,
            self::float($plan['estimated_total_cost'] ?? null),
            $this->details($plan),
            actualRows: $actual,
            loops: $analyzed && is_numeric($plan['actual_loops'] ?? null) ? (int) $plan['actual_loops'] : null,
            time: $analyzed ? self::float($plan['actual_last_row_ms'] ?? null) : null,
        );

        $before = $this->estimateHints();

        foreach (is_array($plan['inputs'] ?? null) ? $plan['inputs'] : [] as $input) {
            if (is_array($input)) {
                $node['children'][] = $this->operation($input, $analyzed);
            }
        }

        if ($analyzed) {
            $this->compareEstimate($id, $operation, $rows, $actual, $before);
        }

        return $node;
    }

    /**
     * MySQL bis 8.2 und MariaDB: ein Teil mit seinem Namen und seinen verschachtelten Teilen.
     *
     * @param  array<mixed>  $part
     * @return PlanNode
     */
    private function part(string $key, array $part, bool $analyzed): array
    {
        if ($key === 'table' || ($key === 'block-nl-join' && isset($part['table']) && is_array($part['table']))) {
            return $this->table($key === 'table' ? $part : array_merge($part['table'], array_diff_key($part, ['table' => true])), $analyzed, $key === 'block-nl-join');
        }

        $id = $this->nextId();
        $operation = match ($key) {
            'query_block' => 'Query block'.(isset($part['select_id']) ? ' #'.self::text($part['select_id']) : ''),
            default => self::PARTS[$key] ?? $key,
        };

        if (($part['using_filesort'] ?? false) === true || $key === 'filesort') {
            $this->filesort($id);
        }

        if (($part['using_temporary_table'] ?? false) === true || $key === 'temporary_table') {
            $this->temporary($id);
        }

        $cost = $part['cost_info']['query_cost'] ?? $part['cost_info']['sort_cost'] ?? $part['cost'] ?? null;
        $node = $this->node(
            $operation,
            null,
            null,
            self::float($cost),
            $this->details($part),
            loops: $analyzed && is_numeric($part['r_loops'] ?? null) ? (int) $part['r_loops'] : null,
            time: $analyzed ? self::float($part['r_total_time_ms'] ?? null) : null,
        );
        $node['children'] = $this->children($part, $analyzed);

        return $node;
    }

    /**
     * @param  array<mixed>  $table
     * @return PlanNode
     */
    private function table(array $table, bool $analyzed, bool $joinBuffer): array
    {
        $id = $this->nextId();
        $name = self::text($table['table_name'] ?? null) ?? '?';
        $access = self::text($table['access_type'] ?? null) ?? '';
        $rows = self::float($table['rows_examined_per_scan'] ?? $table['rows'] ?? null);
        $actual = $analyzed ? self::float($table['r_rows'] ?? null) : null;
        $operation = (self::ACCESS[$access] ?? $access).($joinBuffer ? ' (join buffer)' : '');
        $possible = self::text($table['possible_keys'] ?? null);

        if ($access === 'ALL' && ! str_starts_with($name, '<') && ($rows ?? 0) >= self::LARGE_TABLE) {
            $possible !== null && ! isset($table['key']) ? $this->indexUnused($id, $name, $rows, $possible) : $this->fullScan($id, $name, $rows);
        }

        $node = $this->node(
            $operation,
            $name,
            $rows,
            self::float($table['cost_info']['prefix_cost'] ?? $table['cost'] ?? null),
            $this->details($table),
            actualRows: $actual,
            loops: $analyzed && is_numeric($table['r_loops'] ?? null) ? (int) $table['r_loops'] : null,
            time: $analyzed ? self::float($table['r_table_time_ms'] ?? $table['r_total_time_ms'] ?? null) : null,
        );
        $before = $this->estimateHints();
        $node['children'] = $this->children(array_diff_key($table, ['table' => true]), $analyzed);

        if ($analyzed) {
            $this->compareEstimate($id, $operation.' '.$name, $rows, $actual, $before);
        }

        return $node;
    }

    /**
     * Verschachtelte Teile als Kinder: Objekte unter einem Namen, Listen von Objekten je Eintrag.
     *
     * @param  array<mixed>  $part
     * @return list<PlanNode>
     */
    private function children(array $part, bool $analyzed): array
    {
        $children = [];

        foreach ($part as $key => $value) {
            if (! is_array($value) || ! is_string($key) || in_array($key, ['cost_info', 'used_columns', 'possible_keys', 'used_key_parts', 'ref', 'r_engine_stats', 'sort_fields', 'hash_condition', 'key_length'], true)) {
                continue;
            }

            if (array_is_list($value)) {
                $items = [];

                foreach ($value as $item) {
                    if (is_array($item) && ! array_is_list($item)) {
                        $items[] = count($item) === 1 && is_array(reset($item)) && is_string(array_key_first($item))
                            ? $this->part(array_key_first($item), reset($item), $analyzed)
                            : $this->part($key, $item, $analyzed);
                    }
                }

                // Ein Join über mehrere Tabellen, mehrere Unterabfragen: als eigener Knoten; ein einzelner Eintrag nicht.
                if (count($items) > 1 && isset(self::PARTS[$key])) {
                    $wrapper = $this->node(self::PARTS[$key]);
                    $wrapper['children'] = $items;
                    $items = [$wrapper];
                }

                $children = [...$children, ...$items];

                continue;
            }

            $children[] = $this->part($key, $value, $analyzed);
        }

        return $children;
    }

    /**
     * @param  array<mixed>  $part
     * @return list<string>
     */
    private function details(array $part): array
    {
        $details = [];

        foreach (self::DETAILS as $key) {
            $value = self::text($part[$key] ?? null);

            if ($value !== null && $value !== 'false') {
                $details[] = $key.': '.$value;
            }
        }

        return $details;
    }
}
