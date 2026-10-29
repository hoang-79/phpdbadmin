<?php

namespace App\Services\Shape;

use App\Enums\DatabaseDriver;

/**
 * Eine Tabelle mit Spalten, Indizes und Fremdschlüsseln — die Einheit, die der Vergleich (S-08) und die Migration
 * (S-09) kennen. Checks, die weder eine Aufzählung noch JSON beschreiben, stehen nur zur Anzeige da: Laravel kennt
 * keine Checks.
 */
final readonly class TableShape
{
    /**
     * @param  list<ColumnShape>  $columns
     * @param  list<IndexShape>  $indexes
     * @param  list<ForeignKeyShape>  $foreignKeys
     * @param  list<array{name: string, definition: string}>  $checks
     */
    public function __construct(
        public string $name,
        public DatabaseDriver $driver,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
        public array $checks = [],
        public ?string $comment = null,
    ) {}

    public function column(string $name): ?ColumnShape
    {
        return array_find($this->columns, fn (ColumnShape $column): bool => $column->name === $name)
            ?? array_find($this->columns, fn (ColumnShape $column): bool => strcasecmp($column->name, $name) === 0);
    }

    public function primary(): ?IndexShape
    {
        return array_find($this->indexes, fn (IndexShape $index): bool => $index->kind === IndexShape::PRIMARY);
    }

    /**
     * Ein einzelner Primärschlüssel mit Autoinkrement legt Laravel mit der Spalte selbst an (`id()`, `increments()`).
     */
    public function primaryIsImplicit(): bool
    {
        $primary = $this->primary();

        return $primary !== null && count($primary->columns) === 1 && ($this->column($primary->columns[0])->autoIncrement ?? false);
    }

    /**
     * Index, den MySQL und MariaDB für einen Fremdschlüssel selbst anlegen: gewöhnlich, auf genau dessen Spalten und
     * mit dessen Namen. Andere Treiber brauchen ihn nicht — der Vergleich übergeht ihn, wenn die Gegenseite fehlt.
     */
    public function isImplicitForeignKeyIndex(IndexShape $index): bool
    {
        if (! in_array($this->driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) || $index->kind !== IndexShape::INDEX) {
            return false;
        }

        return array_any($this->foreignKeys, fn (ForeignKeyShape $foreignKey): bool => $foreignKey->name === $index->name
            && array_map('strtolower', $foreignKey->columns) === array_map('strtolower', $index->columns));
    }
}
