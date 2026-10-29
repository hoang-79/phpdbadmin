<?php

namespace App\Services\Transfer;

use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;

/**
 * Eine Tabelle oder Sicht im Export: Spalten mit Daten (ohne generierte — die berechnet die Datenbank beim
 * Re-Import selbst) und Primärschlüssel zum Lesen in Paketen.
 */
final readonly class ExportTable
{
    /**
     * @param  list<ColumnInfo>  $columns  alle Spalten in der Reihenfolge der Tabelle
     * @param  list<string>  $primaryKey
     */
    public function __construct(
        public string $schema,
        public string $name,
        public bool $isView,
        public array $columns,
        public array $primaryKey,
    ) {}

    public static function describe(Connection $target, DatabaseDriver $driver, string $schema, string $name, bool $isView): self
    {
        $primaryKey = [];

        if (! $isView) {
            foreach ($target->getSchemaBuilder()->getIndexes($schema.'.'.$name) as $index) {
                if ((bool) $index['primary']) {
                    $primaryKey = array_map(strval(...), $index['columns']);
                }
            }
        }

        return new self($schema, $name, $isView, ColumnInfo::of($target, $driver, $schema, $name), $primaryKey);
    }

    /**
     * Spalten, deren Werte exportiert werden.
     *
     * @return list<ColumnInfo>
     */
    public function dataColumns(): array
    {
        return array_values(array_filter($this->columns, fn (ColumnInfo $column): bool => ! $column->generated));
    }
}
