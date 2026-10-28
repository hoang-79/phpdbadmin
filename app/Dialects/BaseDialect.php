<?php

namespace App\Dialects;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Was alle Dialekte über Laravels Introspektion gemeinsam haben (ADR-001): Schemas, Tabellen und Sichten, immer mit
 * ausdrücklichem Schema (MariaDB verlangt es, F-1).
 *
 * @phpstan-import-type TableSummary from Dialect
 */
abstract class BaseDialect implements Dialect
{
    public function prepareSession(Connection $connection, bool $readOnly): void
    {
        // MySQL und MariaDB: Startbefehl in der Konfiguration; SQLite: Öffnen nur lesend.
    }

    public function schemas(Connection $connection): array
    {
        return array_map(
            fn (array $schema): string => (string) $schema['name'],
            $connection->getSchemaBuilder()->getSchemas(),
        );
    }

    public function defaultSchema(Connection $connection): ?string
    {
        foreach ($connection->getSchemaBuilder()->getSchemas() as $schema) {
            if ((bool) $schema['default']) {
                return (string) $schema['name'];
            }
        }

        return null;
    }

    public function tables(Connection $connection, string $schema): array
    {
        $builder = $connection->getSchemaBuilder();
        $estimates = $this->rowEstimates($connection, $schema);

        $tables = array_map(fn (array $table): array => [
            'name' => (string) $table['name'],
            'type' => 'table',
            'rows' => $estimates[$table['name']] ?? null,
            'size' => isset($table['size']) ? (int) $table['size'] : null,
            'comment' => isset($table['comment']) && $table['comment'] !== '' ? (string) $table['comment'] : null,
        ], $builder->getTables($schema));

        $views = array_map(fn (array $view): array => [
            'name' => (string) $view['name'],
            'type' => 'view',
            'rows' => null,
            'size' => null,
            'comment' => null,
        ], $builder->getViews($schema));

        $all = [...$tables, ...$views];
        usort($all, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        /** @var list<TableSummary> $all */
        return $all;
    }

    /**
     * Geschätzte Zeilenzahl je Tabelle aus der Statistik des Servers — nie ein count(*) über grosse Tabellen.
     *
     * @return array<string, int>
     */
    abstract protected function rowEstimates(Connection $connection, string $schema): array;

    /**
     * Eine Spalte aus Ergebniszeilen als Liste von Texten.
     *
     * @param  array<int, object>  $rows
     * @return list<string>
     */
    protected function strings(array $rows, string $column): array
    {
        return array_values(array_map(fn (object $row): string => (string) (((array) $row)[$column] ?? ''), $rows));
    }

    /**
     * Ergebniszeilen mit name und definition als Liste von Constraints.
     *
     * @param  array<int, object>  $rows
     * @return list<array{name: string, definition: string}>
     */
    protected function constraints(array $rows): array
    {
        return array_values(array_map(function (object $row): array {
            $values = (array) $row;

            return ['name' => (string) ($values['name'] ?? ''), 'definition' => (string) ($values['definition'] ?? '')];
        }, $rows));
    }

    /**
     * SQLSTATE und Treibercode einer Ausnahme.
     *
     * @return array{0: string|null, 1: int|null}
     */
    protected function errorCodes(Throwable $error): array
    {
        $info = $error instanceof QueryException || $error instanceof \PDOException
            ? ($error->errorInfo ?? [])
            : [];

        $state = isset($info[0]) && is_string($info[0]) ? $info[0] : null;
        $code = isset($info[1]) && is_numeric($info[1]) ? (int) $info[1] : null;

        if ($state === null && preg_match('/SQLSTATE\[(\w{5})\]/', $error->getMessage(), $match) === 1) {
            $state = $match[1];
        }

        return [$state, $code];
    }
}
