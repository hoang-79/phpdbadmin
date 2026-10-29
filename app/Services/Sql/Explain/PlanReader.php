<?php

namespace App\Services\Sql\Explain;

/**
 * Liest den Plan eines Treibers in einen einheitlichen Baum (Q-06): Knoten mit Operation, Objekt, geschätzten und
 * (mit ANALYZE) tatsächlichen Zeilen, Kosten, Zeit und Details — und sammelt Hinweise dazu. Die Wörter der Operationen
 * bleiben die des Servers («Seq Scan», «Table scan on orders»); übersetzt sind die Hinweise.
 *
 * @phpstan-type PlanNode array{id: int, operation: string, object: string|null, rows: float|null, actualRows: float|null, loops: int|null, cost: float|null, time: float|null, details: list<string>, children: list<mixed>}
 * @phpstan-type PlanHint array{kind: 'full_scan'|'filesort'|'temporary'|'index_unused'|'estimate', node: int, message: string}
 */
abstract class PlanReader
{
    /** Ab so vielen Zeilen gilt eine Tabelle als gross genug für den Hinweis «vollständiger Durchlauf». */
    public const int LARGE_TABLE = 1000;

    /** Schätzung «stark daneben»: mindestens zehnfach und mindestens so viele Zeilen Unterschied. */
    private const int ESTIMATE_FACTOR = 10;

    private const int ESTIMATE_DIFFERENCE = 100;

    private int $next = 0;

    /** @var list<PlanHint> */
    private array $hints = [];

    /**
     * @param  PlanNode  $plan
     * @return array{plan: PlanNode, hints: list<PlanHint>}
     */
    protected function result(array $plan): array
    {
        return ['plan' => $plan, 'hints' => $this->hints];
    }

    /**
     * @param  list<string>  $details
     * @param  list<mixed>  $children
     * @return PlanNode
     */
    protected function node(string $operation, ?string $object = null, ?float $rows = null, ?float $cost = null, array $details = [], array $children = [], ?float $actualRows = null, ?int $loops = null, ?float $time = null): array
    {
        return [
            'id' => $this->next++,
            'operation' => $operation,
            'object' => $object,
            'rows' => $rows,
            'actualRows' => $actualRows,
            'loops' => $loops,
            'cost' => $cost,
            'time' => $time,
            'details' => array_values(array_filter($details, fn (string $detail): bool => trim($detail) !== '')),
            'children' => $children,
        ];
    }

    /** Die Kennung, die der nächste Knoten bekommt — für Hinweise, bevor der Knoten fertig ist. */
    protected function nextId(): int
    {
        return $this->next;
    }

    protected function fullScan(int $node, string $table, ?float $rows): void
    {
        $this->hints[] = ['kind' => 'full_scan', 'node' => $node, 'message' => $rows === null
            ? __('Vollständiger Durchlauf über «:table» — ein Index auf die Spalten in WHERE oder JOIN könnte helfen.', ['table' => $table])
            : __('Vollständiger Durchlauf über «:table» (≈ :rows Zeilen) — ein Index auf die Spalten in WHERE oder JOIN könnte helfen.', ['table' => $table, 'rows' => $this->number($rows)])];
    }

    protected function filesort(int $node): void
    {
        $this->hints[] = ['kind' => 'filesort', 'node' => $node, 'message' => $this->filesortText()];
    }

    protected function temporary(int $node): void
    {
        $this->hints[] = ['kind' => 'temporary', 'node' => $node, 'message' => $this->temporaryText()];
    }

    /** Vollständiger Durchlauf, obwohl ein Index in Frage käme — der Server hält ihn hier für teurer. */
    protected function indexUnused(int $node, string $table, ?float $rows, string $keys): void
    {
        $this->hints[] = ['kind' => 'index_unused', 'node' => $node, 'message' => __('Vollständiger Durchlauf über «:table» (≈ :rows Zeilen), obwohl ein Index in Frage käme (:keys) — der Server hält ihn hier für teurer.', [
            'table' => $table,
            'rows' => $this->number($rows ?? 0),
            'keys' => $keys,
        ])];
    }

    /** Wie viele Hinweise auf eine falsche Schätzung es schon gibt — um nur die tiefste Stelle zu nennen. */
    protected function estimateHints(): int
    {
        return count(array_filter($this->hints, fn (array $hint): bool => $hint['kind'] === 'estimate'));
    }

    /**
     * Mit ANALYZE: Schätzung und Wirklichkeit weit auseinander (je Durchgang). Nach den Kindern aufrufen: Hat schon
     * ein Kind den Hinweis, erbt dieser Knoten nur dessen Fehler und bekommt keinen eigenen.
     */
    protected function compareEstimate(int $node, string $operation, ?float $estimated, ?float $actual, int $before): void
    {
        if ($estimated === null || $actual === null || $this->estimateHints() > $before) {
            return;
        }

        $high = max($estimated, $actual);
        $low = max(1.0, min($estimated, $actual));

        if ($high / $low >= self::ESTIMATE_FACTOR && $high - $low >= self::ESTIMATE_DIFFERENCE) {
            $this->hints[] = ['kind' => 'estimate', 'node' => $node, 'message' => __('Schätzung stark daneben bei «:operation»: erwartet ≈ :estimated, tatsächlich :actual Zeilen — oft hilft es, die Statistik zu aktualisieren (ANALYZE).', [
                'operation' => $operation,
                'estimated' => $this->number($estimated),
                'actual' => $this->number($actual),
            ])];
        }
    }

    protected function number(float $value): string
    {
        // Wie die Oberfläche (Intl de-CH und en-CH): Hochkomma als Tausendertrennzeichen.
        return number_format($value, 0, '.', '’');
    }

    protected static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) && $value !== '' => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value => implode(', ', array_map('strval', $value)),
            default => null,
        };
    }

    private function filesortText(): string
    {
        return __('Sortieren ohne passenden Index (Filesort) — ein Index in der Reihenfolge von ORDER BY erspart das Sortieren.');
    }

    private function temporaryText(): string
    {
        return __('Temporäre Tabelle für GROUP BY, DISTINCT oder UNION — bei vielen Zeilen teuer.');
    }
}
