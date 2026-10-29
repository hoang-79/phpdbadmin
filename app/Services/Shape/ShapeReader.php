<?php

namespace App\Services\Shape;

use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use App\Services\Catalog\Structure;
use Illuminate\Database\Connection;

/**
 * Liest Tabellen so, dass sie sich vergleichen (S-08) und als Migration schreiben lassen (S-09): Laravels
 * Introspektion für Spalten, Indizes und Fremdschlüssel, der Dialekt für Checks. Die Art einer Spalte folgt denselben
 * Regeln wie die Strukturansicht (O-04): JSON in MariaDB über den Check `json_valid`, eigene Aufzählungstypen in
 * PostgreSQL als Auswahl.
 */
final class ShapeReader
{
    /**
     * Alle Tabellen eines Schemas (ohne Sichten).
     *
     * @throws ShapeTooLarge
     */
    public function schema(Connection $connection, DatabaseDriver $driver, string $schema): SchemaShape
    {
        $names = array_values(array_map(
            fn (array $table): string => $table['name'],
            array_filter($driver->dialect()->tables($connection, $schema), fn (array $table): bool => $table['type'] === 'table'),
        ));

        $limit = (int) config('phpdbadmin.compare.max_tables', 500);

        if (count($names) > $limit) {
            throw new ShapeTooLarge(count($names), $limit);
        }

        $enumTypes = $this->enumTypes($connection, $driver);
        $tables = [];

        foreach ($names as $name) {
            $tables[$name] = $this->read($connection, $driver, $schema, $name, $enumTypes);
        }

        return new SchemaShape($driver, $schema, $tables);
    }

    /**
     * Eine Tabelle; null, wenn es sie im Schema nicht gibt (oder sie eine Sicht ist).
     */
    public function table(Connection $connection, DatabaseDriver $driver, string $schema, string $table): ?TableShape
    {
        $exists = array_any($driver->dialect()->tables($connection, $schema), fn (array $summary): bool => $summary['name'] === $table && $summary['type'] === 'table');

        return $exists ? $this->read($connection, $driver, $schema, $table, $this->enumTypes($connection, $driver)) : null;
    }

    /**
     * @param  list<string>  $enumTypes
     */
    private function read(Connection $connection, DatabaseDriver $driver, string $schema, string $table, array $enumTypes): TableShape
    {
        $builder = $connection->getSchemaBuilder();
        $qualified = $schema.'.'.$table;
        $checks = $driver->dialect()->checkConstraints($connection, $schema, $table);
        $jsonColumns = Structure::jsonCheckedColumns($checks);
        $usedChecks = [];

        $columns = [];
        foreach ($builder->getColumns($qualified) as $column) {
            $name = (string) $column['name'];
            $typeName = (string) $column['type_name'];
            $type = (string) $column['type'];
            $kind = match (true) {
                in_array($name, $jsonColumns, true) => ColumnKind::Json,
                in_array($typeName, $enumTypes, true) => ColumnKind::Choice,
                default => ColumnKind::of($typeName, $type),
            };

            // Laravels enum() legt in PostgreSQL und SQLite einen Text mit Check an — der Check gehört zur Spalte.
            $allowed = in_array($driver, [DatabaseDriver::Postgres, DatabaseDriver::Sqlite], true) && $kind === ColumnKind::Text
                ? LaravelTypes::enumFromChecks($name, $checks)
                : null;

            if ($allowed !== null) {
                $usedChecks[] = $name;
            }

            $autoIncrement = (bool) $column['auto_increment'];
            $generation = is_array($column['generation'] ?? null) && ($column['generation']['expression'] ?? null) !== null
                ? ['type' => (string) $column['generation']['type'], 'expression' => (string) $column['generation']['expression']]
                : null;

            $columns[] = new ColumnShape(
                name: $name,
                type: strtolower($type),
                kind: $kind,
                nullable: (bool) $column['nullable'],
                default: $generation === null ? DefaultValue::fromIntrospection($column['default'] !== null ? (string) $column['default'] : null, $driver, $autoIncrement) : null,
                autoIncrement: $autoIncrement,
                laravel: LaravelTypes::for($driver, $typeName, $type, $kind, $autoIncrement, $allowed, $name),
                comment: ($column['comment'] ?? '') !== '' ? (string) $column['comment'] : null,
                generated: $generation,
            );
        }

        // Übrig bleiben Checks, die keine Spalte beschreibt — Laravel kennt sie nicht, sie stehen nur zur Anzeige da.
        $otherChecks = array_values(array_filter($checks, fn (array $check): bool => Structure::jsonCheckedColumns([$check]) === []
            && ! array_any($usedChecks, fn (string $column): bool => LaravelTypes::enumFromChecks($column, [$check]) !== null)));

        return new TableShape(
            name: $table,
            driver: $driver,
            columns: $columns,
            indexes: array_map(fn (array $index): IndexShape => IndexShape::fromIntrospection($index), $builder->getIndexes($qualified)),
            foreignKeys: array_map(fn (array $foreignKey): ForeignKeyShape => ForeignKeyShape::fromIntrospection($foreignKey), $builder->getForeignKeys($qualified)),
            checks: $otherChecks,
        );
    }

    /**
     * Eigene Aufzählungstypen in PostgreSQL (`create type … as enum`).
     *
     * @return list<string>
     */
    private function enumTypes(Connection $connection, DatabaseDriver $driver): array
    {
        if ($driver !== DatabaseDriver::Postgres) {
            return [];
        }

        return array_values(array_map(
            fn (array $type): string => (string) $type['name'],
            array_filter($connection->getSchemaBuilder()->getTypes(), fn (array $type): bool => $type['type'] === 'enum'),
        ));
    }
}
