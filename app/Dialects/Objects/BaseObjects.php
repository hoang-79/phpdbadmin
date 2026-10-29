<?php

namespace App\Dialects\Objects;

use Illuminate\Database\Connection;

/**
 * Gemeinsame Bausteine der Objekt-Dialekte: Ergebniszeilen lesen, Bezeichner quoten, Fremdschlüssel gruppieren.
 *
 * @phpstan-import-type DiagramRelation from ObjectDialect
 * @phpstan-import-type Fact from ObjectDefinition
 */
abstract class BaseObjects implements ObjectDialect
{
    public function types(Connection $connection, string $schema): ?array
    {
        return null;
    }

    /** Ein Bezeichner, das Anführungszeichen darin verdoppelt. */
    abstract public function quote(string $identifier): string;

    protected function qualified(string $schema, string $name): string
    {
        return $this->quote($schema).'.'.$this->quote($name);
    }

    /**
     * Eine Zeile als Array mit Texten, Schlüssel klein geschrieben (MySQL liefert information_schema gross).
     *
     * @return array<string, string|null>
     */
    protected function row(?object $row): array
    {
        if ($row === null) {
            return [];
        }

        $values = [];
        foreach ((array) $row as $key => $value) {
            $values[strtolower((string) $key)] = $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return $values;
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<array<string, string|null>>
     */
    protected function rows(array $rows): array
    {
        return array_values(array_map(fn (object $row): array => $this->row($row), $rows));
    }

    /**
     * Nur Angaben mit Wert, in der gegebenen Reihenfolge.
     *
     * @param  array<string, string|null>  $facts
     * @return list<Fact>
     */
    protected function facts(array $facts): array
    {
        $list = [];

        foreach ($facts as $key => $value) {
            if ($value !== null && trim($value) !== '') {
                $list[] = ['key' => $key, 'value' => trim($value)];
            }
        }

        return $list;
    }

    /**
     * Zeilen eines Fremdschlüssels (eine je Spalte, in Reihenfolge) zu Beziehungen zusammenfassen.
     *
     * @param  list<array<string, string|null>>  $rows  name, tbl, col, ref_tbl, ref_col
     * @return list<DiagramRelation>
     */
    protected function groupRelations(array $rows): array
    {
        $relations = [];

        foreach ($rows as $row) {
            $key = $row['tbl'].'#'.$row['name'];
            $relations[$key] ??= ['name' => (string) $row['name'], 'table' => (string) $row['tbl'], 'columns' => [], 'foreignTable' => (string) $row['ref_tbl'], 'foreignColumns' => []];
            $relations[$key]['columns'][] = (string) $row['col'];
            $relations[$key]['foreignColumns'][] = (string) $row['ref_col'];
        }

        return array_values($relations);
    }
}
