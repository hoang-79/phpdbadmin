<?php

namespace App\Services\Catalog;

use App\Connections\TargetConnections;
use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;

/**
 * Struktur einer Tabelle oder Sicht (S-01, Wireframe 08): Spalten mit Typ im Original und Art (O-04), Indizes,
 * Fremdschlüssel in beide Richtungen, Check-Constraints, Trigger und DDL. Laravel liest Spalten, Indizes und
 * Fremdschlüssel; der Dialekt ergänzt den Rest.
 */
final class Structure
{
    public function __construct(private readonly TargetConnections $targets) {}

    /**
     * @return array<string, mixed>|null null, wenn es das Objekt im Schema nicht gibt
     */
    public function describe(DatabaseConnection $connection, string $schema, string $object, ?string $database = null): ?array
    {
        $target = $this->targets->open($connection, $database);
        $dialect = $connection->driver->dialect();
        $builder = $target->getSchemaBuilder();

        $summary = array_find($dialect->tables($target, $schema), fn (array $table): bool => $table['name'] === $object);

        if ($summary === null) {
            return null;
        }

        $isView = $summary['type'] === 'view';
        $qualified = $schema.'.'.$object;
        $details = $isView ? [] : (array_find($builder->getTables($schema), fn (array $table): bool => $table['name'] === $object) ?? []);

        $indexes = $isView ? [] : $builder->getIndexes($qualified);
        $foreignKeys = $isView ? [] : $builder->getForeignKeys($qualified);
        $primary = array_find($indexes, fn (array $index): bool => (bool) $index['primary'])['columns'] ?? [];

        $references = [];
        foreach ($foreignKeys as $foreignKey) {
            foreach ($foreignKey['columns'] as $position => $column) {
                $references[$column] = ['table' => $foreignKey['foreign_table'], 'column' => $foreignKey['foreign_columns'][$position] ?? null];
            }
        }

        $checks = $isView ? [] : $dialect->checkConstraints($target, $schema, $object);
        $jsonColumns = $this->jsonCheckedColumns($checks);
        $enumTypes = $connection->driver === DatabaseDriver::Postgres ? $this->enumTypes($builder->getTypes()) : [];

        $columns = [];
        foreach ($builder->getColumns($qualified) as $position => $column) {
            $kind = match (true) {
                in_array($column['name'], $jsonColumns, true) => ColumnKind::Json,
                in_array($column['type_name'], $enumTypes, true) => ColumnKind::Choice,
                default => ColumnKind::of((string) $column['type_name'], (string) $column['type']),
            };
            $columns[] = [
                'position' => $position + 1,
                'name' => (string) $column['name'],
                'type' => (string) $column['type'],
                'kind' => $kind->value,
                'kindLabel' => $kind->label(),
                'nullable' => (bool) $column['nullable'],
                'default' => $column['default'] !== null ? (string) $column['default'] : null,
                'autoIncrement' => (bool) $column['auto_increment'],
                'collation' => $column['collation'] ?? null,
                'comment' => ($column['comment'] ?? '') !== '' ? $column['comment'] : null,
                'generated' => $column['generation']['expression'] ?? null,
                'primary' => in_array($column['name'], $primary, true),
                'references' => $references[$column['name']] ?? null,
            ];
        }

        return [
            'object' => [
                'name' => $object,
                'kind' => $isView ? 'view' : 'table',
                'rows' => $summary['rows'],
                'size' => $details['size'] ?? null,
                'comment' => ($details['comment'] ?? '') !== '' ? $details['comment'] : null,
                'engine' => $details['engine'] ?? null,
                'collation' => $details['collation'] ?? null,
            ],
            'columns' => $columns,
            'indexes' => array_map(fn (array $index): array => [
                'name' => (string) $index['name'],
                'columns' => $index['columns'],
                'type' => $index['type'],
                'unique' => (bool) $index['unique'],
                'primary' => (bool) $index['primary'],
            ], $indexes),
            'foreignKeys' => array_map(fn (array $foreignKey): array => [
                'name' => (string) ($foreignKey['name'] ?? ''),
                'columns' => $foreignKey['columns'],
                'foreignSchema' => $foreignKey['foreign_schema'] ?? null,
                'foreignTable' => (string) $foreignKey['foreign_table'],
                'foreignColumns' => $foreignKey['foreign_columns'],
                'onUpdate' => strtolower((string) ($foreignKey['on_update'] ?? 'no action')),
                'onDelete' => strtolower((string) ($foreignKey['on_delete'] ?? 'no action')),
            ], $foreignKeys),
            'referencedBy' => $isView ? [] : $dialect->referencingForeignKeys($target, $schema, $object),
            'checks' => $checks,
            'triggers' => $isView ? [] : $dialect->triggers($target, $schema, $object),
            'ddl' => $dialect->ddl($target, $schema, $object, $isView),
        ];
    }

    /**
     * MariaDB kennt JSON nur als Alias: `longtext` mit dem Check `json_valid(spalte)`. Die Art folgt dem Check.
     *
     * @param  list<array{name: string, definition: string}>  $checks
     * @return list<string>
     */
    private function jsonCheckedColumns(array $checks): array
    {
        $columns = [];

        foreach ($checks as $check) {
            if (preg_match('/^\s*json_valid\(\s*[`"]?([^`")]+)[`"]?\s*\)\s*$/i', $check['definition'], $match) === 1) {
                $columns[] = $match[1];
            }
        }

        return $columns;
    }

    /**
     * Eigene Aufzählungstypen in PostgreSQL (`create type … as enum`) sind «Auswahl»; Laravels `enum()` dagegen legt
     * dort `varchar` mit Check an und bleibt «Text».
     *
     * @param  list<array<string, mixed>>  $types
     * @return list<string>
     */
    private function enumTypes(array $types): array
    {
        return array_values(array_map(
            fn (array $type): string => (string) $type['name'],
            array_filter($types, fn (array $type): bool => ($type['type'] ?? null) === 'enum'),
        ));
    }
}
