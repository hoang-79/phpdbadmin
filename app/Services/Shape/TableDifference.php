<?php

namespace App\Services\Shape;

/**
 * Unterschiede einer Tabelle: ganz neu (nur in der Quelle), nur im Ziel, oder geändert mit den einzelnen Spalten,
 * Indizes und Fremdschlüsseln.
 *
 * @phpstan-type ColumnDifference array{name: string, status: Comparison::ADDED|Comparison::REMOVED|Comparison::CHANGED, source: ColumnShape|null, target: ColumnShape|null, changes: list<'type'|'length'|'nullable'|'default'|'autoIncrement'>}
 * @phpstan-type IndexDifference array{status: Comparison::ADDED|Comparison::REMOVED, source: IndexShape|null, target: IndexShape|null}
 * @phpstan-type ForeignKeyDifference array{status: Comparison::ADDED|Comparison::REMOVED|Comparison::CHANGED, source: ForeignKeyShape|null, target: ForeignKeyShape|null}
 */
final readonly class TableDifference
{
    /**
     * @param  Comparison::ADDED|Comparison::REMOVED|Comparison::CHANGED  $status
     * @param  list<ColumnDifference>  $columns
     * @param  list<IndexDifference>  $indexes
     * @param  list<ForeignKeyDifference>  $foreignKeys
     */
    public function __construct(
        public string $name,
        public string $status,
        public ?TableShape $source,
        public ?TableShape $target,
        public array $columns = [],
        public array $indexes = [],
        public array $foreignKeys = [],
    ) {}
}
