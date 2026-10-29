<?php

namespace App\Services\Sql\Explain;

use Closure;

/**
 * Plan von PostgreSQL aus `EXPLAIN (FORMAT JSON, VERBOSE)`: jeder Knoten mit «Plans» darunter. Zeilen und Zeit mit
 * ANALYZE gelten je Durchgang («Actual Loops») — so vergleicht PostgreSQL sie auch mit der Schätzung.
 *
 * @phpstan-import-type PlanNode from PlanReader
 * @phpstan-import-type PlanHint from PlanReader
 */
final class PostgresPlanReader extends PlanReader
{
    /** Bedingungen und Schlüssel, die als Details erscheinen. */
    private const array DETAILS = [
        'Join Type', 'Strategy', 'Index Name', 'Index Cond', 'Recheck Cond', 'Filter', 'Hash Cond', 'Merge Cond', 'Join Filter',
        'Sort Key', 'Group Key', 'Sort Method', 'Rows Removed by Filter', 'Rows Removed by Index Recheck', 'Subplan Name', 'CTE Name',
        'Function Name', 'Parent Relationship',
    ];

    /** Ab so vielen Zeilen ist ein Sortieren ohne Index einen Hinweis wert. */
    private const int LARGE_SORT = 10000;

    /**
     * @param  Closure(string|null, string): (float|null)  $tableRows  geschätzte Zeilen einer Tabelle (Schema, Name)
     */
    public function __construct(private readonly Closure $tableRows) {}

    /**
     * @param  array<mixed>  $explain  das JSON des Servers
     * @return array{plan: PlanNode, hints: list<PlanHint>}
     */
    public function read(array $explain, bool $analyzed): array
    {
        $top = is_array($explain[0] ?? null) ? $explain[0] : [];
        $plan = is_array($top['Plan'] ?? null) ? $top['Plan'] : ['Node Type' => 'Result'];

        return $this->result($this->walk($plan, $analyzed));
    }

    /**
     * @param  array<mixed>  $plan
     * @return PlanNode
     */
    private function walk(array $plan, bool $analyzed): array
    {
        $id = $this->nextId();
        $type = self::text($plan['Node Type'] ?? null) ?? '?';
        $relation = self::text($plan['Relation Name'] ?? null);
        $alias = self::text($plan['Alias'] ?? null);
        $object = $relation !== null
            ? $relation.($alias !== null && $alias !== $relation ? ' '.$alias : '')
            : self::text($plan['CTE Name'] ?? null) ?? self::text($plan['Function Name'] ?? null) ?? ($type === 'Subquery Scan' ? $alias : null);
        $rows = self::float($plan['Plan Rows'] ?? null);
        $actual = $analyzed ? self::float($plan['Actual Rows'] ?? null) : null;

        $details = [];
        foreach (self::DETAILS as $key) {
            $value = self::text($plan[$key] ?? null);

            if ($value !== null) {
                $details[] = $key.': '.$value;
            }
        }

        if (($plan['Sort Space Type'] ?? null) !== null) {
            $details[] = 'Sort Space: '.self::text($plan['Sort Space Used'] ?? null).' kB ('.self::text($plan['Sort Space Type']).')';
        }

        if ($type === 'Seq Scan' && $relation !== null) {
            $scanned = ($this->tableRows)(self::text($plan['Schema'] ?? null), $relation) ?? $rows;

            if ($scanned !== null && $scanned >= self::LARGE_TABLE) {
                $this->fullScan($id, $relation, $scanned);
            }
        }

        if ($type === 'Sort' && (($rows ?? 0) >= self::LARGE_SORT || ($plan['Sort Space Type'] ?? null) === 'Disk')) {
            $this->filesort($id);
        }

        // Kinder erst nach den Hinweisen dieses Knotens: die Kennung oben gehört zu ihm.
        $node = $this->node(
            $type,
            $object,
            $rows,
            self::float($plan['Total Cost'] ?? null),
            $details,
            actualRows: $actual,
            loops: $analyzed && is_numeric($plan['Actual Loops'] ?? null) ? (int) $plan['Actual Loops'] : null,
            time: $analyzed ? self::float($plan['Actual Total Time'] ?? null) : null,
        );

        $before = $this->estimateHints();

        foreach (is_array($plan['Plans'] ?? null) ? $plan['Plans'] : [] as $child) {
            if (is_array($child)) {
                $node['children'][] = $this->walk($child, $analyzed);
            }
        }

        if ($analyzed) {
            $this->compareEstimate($id, $type.($object !== null ? ' '.$object : ''), $rows, $actual, $before);
        }

        return $node;
    }
}
