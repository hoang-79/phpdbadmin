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
     * Kopf eines Objekts (Wireframes 05, 08): Art, geschätzte Zeilen, Grösse, Kommentar, Engine, Kollation.
     *
     * @return array{name: string, kind: 'table'|'view', rows: int|null, size: int|null, comment: string|null, engine: string|null, collation: string|null}|null null, wenn es das Objekt im Schema nicht gibt
     */
    public function summary(DatabaseConnection $connection, string $schema, string $object, ?string $database = null): ?array
    {
        $target = $this->targets->open($connection, $database);
        $summary = array_find($connection->driver->dialect()->tables($target, $schema), fn (array $table): bool => $table['name'] === $object);

        if ($summary === null) {
            return null;
        }

        $isView = $summary['type'] === 'view';
        $details = $isView ? [] : (array_find($target->getSchemaBuilder()->getTables($schema), fn (array $table): bool => $table['name'] === $object) ?? []);

        return [
            'name' => $object,
            'kind' => $isView ? 'view' : 'table',
            'rows' => $summary['rows'],
            'size' => isset($details['size']) ? (int) $details['size'] : null,
            'comment' => ($details['comment'] ?? '') !== '' ? (string) $details['comment'] : null,
            'engine' => isset($details['engine']) ? (string) $details['engine'] : null,
            'collation' => isset($details['collation']) ? (string) $details['collation'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null null, wenn es das Objekt im Schema nicht gibt
     */
    public function describe(DatabaseConnection $connection, string $schema, string $object, ?string $database = null): ?array
    {
        $summary = $this->summary($connection, $schema, $object, $database);

        if ($summary === null) {
            return null;
        }

        $target = $this->targets->open($connection, $database);
        $dialect = $connection->driver->dialect();
        $builder = $target->getSchemaBuilder();
        $isView = $summary['kind'] === 'view';
        $qualified = $schema.'.'.$object;

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
                'default' => $column['default'] !== null ? $this->displayDefault((string) $column['default'], $connection->driver, $kind) : null,
                'autoIncrement' => (bool) $column['auto_increment'],
                'collation' => $column['collation'] ?? null,
                'comment' => ($column['comment'] ?? '') !== '' ? $column['comment'] : null,
                'generated' => $column['generation']['expression'] ?? null,
                'primary' => in_array($column['name'], $primary, true),
                'references' => $references[$column['name']] ?? null,
            ];
        }

        return [
            'object' => $summary,
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
     * Vorgabe als SQL-Literal, gleich in allen vier Datenbanken (O-04): PostgreSQL hängt eine Typumwandlung an
     * («'offen'::character varying»), MySQL liefert Text ohne Anführungszeichen («offen»). Ausdrücke wie
     * CURRENT_TIMESTAMP bleiben, wie sie sind.
     */
    private function displayDefault(string $default, DatabaseDriver $driver, ColumnKind $kind): string
    {
        if ($driver === DatabaseDriver::Postgres && preg_match("/^('(?:[^']|'')*'|NULL)::[\\w\\s\"\\[\\]().,]+$/i", $default, $match) === 1) {
            return $match[1];
        }

        $isLiteralText = in_array($kind, [ColumnKind::Text, ColumnKind::Choice], true)
            && ! str_starts_with($default, "'")
            && preg_match('/^[a-z_]+\\(.*\\)$|^current_(timestamp|date|time)/i', $default) !== 1;

        if ($driver === DatabaseDriver::MySql && $isLiteralText) {
            return "'".str_replace("'", "''", $default)."'";
        }

        return $default;
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
