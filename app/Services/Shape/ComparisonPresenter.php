<?php

namespace App\Services\Shape;

/**
 * Ein Vergleich für die Seite (S-08): je Tabelle eine Liste der Unterschiede mit Beschriftung in der Sprache des
 * Kontos — Spalten im Original mit NULL und Vorgabe, Indizes und Fremdschlüssel in Kurzform.
 *
 * @phpstan-type PresentedItem array{kind: 'column'|'index'|'foreignKey', name: string, status: string, changes: list<string>, source: string|null, target: string|null}
 * @phpstan-type PresentedTable array{name: string, status: string, items: list<PresentedItem>}
 */
final class ComparisonPresenter
{
    /**
     * @return list<PresentedTable>
     */
    public function tables(Comparison $comparison): array
    {
        return array_map(fn (TableDifference $table): array => [
            'name' => $table->name,
            'status' => $table->status,
            'items' => $table->status === Comparison::CHANGED ? $this->items($table) : [],
        ], $comparison->tables);
    }

    /**
     * @return list<PresentedItem>
     */
    private function items(TableDifference $table): array
    {
        $items = [];

        foreach ($table->columns as $column) {
            $items[] = [
                'kind' => 'column',
                'name' => $column['name'],
                'status' => $column['status'],
                'changes' => array_map(fn (string $change): string => $this->changeLabel($change), $column['changes']),
                'source' => $column['source']?->summary(),
                'target' => $column['target']?->summary(),
            ];
        }

        foreach ($table->indexes as $index) {
            $shape = $index['source'] ?? $index['target'];
            $items[] = [
                'kind' => 'index',
                'name' => $shape?->summary() ?? '',
                'status' => $index['status'],
                'changes' => [],
                'source' => $index['source']?->name,
                'target' => $index['target']?->name,
            ];
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $items[] = [
                'kind' => 'foreignKey',
                'name' => ($foreignKey['source'] ?? $foreignKey['target'])?->summary() ?? '',
                'status' => $foreignKey['status'],
                'changes' => $foreignKey['status'] === Comparison::CHANGED ? [$this->rulesLabel()] : [],
                'source' => $foreignKey['source']?->summary(),
                'target' => $foreignKey['target']?->summary(),
            ];
        }

        return $items;
    }

    private function changeLabel(string $change): string
    {
        return match ($change) {
            'type' => __('Typ'),
            'length' => __('Länge'),
            'nullable' => 'NULL',
            'default' => __('Vorgabe'),
            'autoIncrement' => __('Autoinkrement'),
            default => $change,
        };
    }

    private function rulesLabel(): string
    {
        return __('Regeln (ON UPDATE, ON DELETE)');
    }
}
