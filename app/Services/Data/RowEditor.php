<?php

namespace App\Services\Data;

use App\Connections\TargetConnections;
use App\Enums\ColumnKind;
use App\Models\AuditEntry;
use App\Models\ChangeSnapshot;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\SqlMask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Daten bearbeiten (D-04, D-07, D-09, N-03, ADR-003): gesammelte Änderungen zuerst als Vorschau (aus `pretend()`,
 * Werte zur Anzeige eingesetzt, mitgelöschte Kindzeilen gezählt), dann in **einer** Transaktion angewendet — jede
 * Änderung trifft genau eine Zeile über den Primärschlüssel, sonst wird alles zurückgerollt. Vorher- und
 * Nachher-Werte werden für «Rückgängig» gesichert; ins Protokoll kommt das SQL nur ohne Werte.
 *
 * @phpstan-type Change array{op: 'insert'|'update'|'delete', key: list<string|null>|null, values: array<string, string|int|float|bool|null>|null}
 *
 * @phpstan-import-type GridColumn from TableData
 */
final class RowEditor
{
    public const int MAX_CHANGES = 1000;

    public function __construct(
        private readonly TargetConnections $targets,
        private readonly TableData $data,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<Change>  $changes
     * @return array{statements: list<array{sql: string, op: string, rows: int|null, cascades: list<array{table: string, count: int}>}>, driver: string}
     *
     * @throws ChangeRejected
     */
    public function preview(DatabaseConnection $connection, ?string $database, string $schema, string $table, array $changes): array
    {
        [$columns, $primaryKey] = $this->describe($connection, $database, $schema, $table, $changes);
        $target = $this->targets->open($connection, $database);
        $dialect = $connection->driver->dialect();
        $cascading = array_values(array_filter($dialect->referencingForeignKeys($target, $schema, $table), fn (array $reference): bool => $reference['onDelete'] === 'cascade'));

        $statements = [];
        foreach ($changes as $change) {
            $queries = $target->pretend(fn (Connection $pretending) => $this->statement($pretending, $schema, $table, $primaryKey, $change, $columns));

            foreach ($queries as $query) {
                $statements[] = [
                    'sql' => $this->display($target, $query['query'], $query['bindings']),
                    'op' => $change['op'],
                    'rows' => $change['op'] === 'insert' ? null : 1,
                    'cascades' => $change['op'] === 'delete' ? $this->cascades($connection, $target, $schema, $table, $primaryKey, $change['key'] ?? [], $cascading) : [],
                ];
            }
        }

        return ['statements' => $statements, 'driver' => $connection->driver->value];
    }

    /**
     * @param  list<Change>  $changes
     * @return array{auditId: int, inserted: int, updated: int, deleted: int, undoable: bool}
     *
     * @throws ChangeRejected
     * @throws ChangeConflict
     */
    public function apply(DatabaseConnection $connection, User $user, ?string $database, string $schema, string $table, array $changes): array
    {
        [$columns, $primaryKey] = $this->describe($connection, $database, $schema, $table, $changes);
        $statements = [];

        $snapshots = $this->targets->withWritable($connection, fn (Connection $writer): array => $writer->transaction(function () use ($writer, $schema, $table, $primaryKey, $changes, $columns, &$statements): array {
            $snapshots = [];

            foreach ($changes as $position => $change) {
                $key = $change['key'] ?? [];
                $before = $change['op'] === 'insert' ? null : $this->row($writer, $schema, $table, $primaryKey, $key);

                if ($change['op'] !== 'insert' && $before === null) {
                    throw new ChangeConflict(__('Eine Zeile gibt es nicht mehr — vielleicht hat sie jemand anderes gelöscht. Nichts wurde geändert.'));
                }

                $queries = $writer->pretend(fn (Connection $pretending) => $this->statement($pretending, $schema, $table, $primaryKey, $change, $columns));
                $statements[] = implode(";\n", array_column($queries, 'query'));

                if ($change['op'] === 'insert') {
                    $key = $this->insert($writer, $schema, $table, $primaryKey, $this->writable($change['values'] ?? [], $columns, $writer));
                } else {
                    $affected = $this->execute($writer, $schema, $table, $primaryKey, $change, $columns);

                    if ($affected !== 1) {
                        throw new ChangeConflict(__('Eine Änderung hätte :count Zeilen getroffen statt einer. Nichts wurde geändert.', ['count' => $affected]));
                    }
                }

                $snapshots[] = [
                    'position' => $position,
                    'operation' => $change['op'],
                    'key' => $key,
                    'before' => $before === null ? null : $this->portable($before),
                    'after' => $change['op'] === 'delete' ? null : $this->portable($this->row($writer, $schema, $table, $primaryKey, $key) ?? []),
                ];
            }

            return $snapshots;
        }), $database);

        $counts = array_count_values(array_column($snapshots, 'operation'));
        $entry = $this->audit->record('data.change', $user, $schema.'.'.$table, [
            'connection_id' => $connection->id,
            'inserted' => $counts['insert'] ?? 0,
            'updated' => $counts['update'] ?? 0,
            'deleted' => $counts['delete'] ?? 0,
            'affected_rows' => count($snapshots),
        ], SqlMask::mask(implode(";\n", $statements)));

        $undoable = count($snapshots) <= (int) config('phpdbadmin.data.undo_rows', 1000);

        if ($undoable) {
            $expires = CarbonImmutable::now()->addDays((int) config('phpdbadmin.data.undo_days', 7));

            foreach ($snapshots as $snapshot) {
                ChangeSnapshot::query()->create([
                    ...$snapshot,
                    'audit_log_id' => $entry->id,
                    'user_id' => $user->id,
                    'connection_id' => $connection->id,
                    'database' => $database,
                    'schema' => $schema,
                    'target' => $table,
                    'expires_at' => $expires,
                ]);
            }
        }

        return [
            'auditId' => $entry->id,
            'inserted' => $counts['insert'] ?? 0,
            'updated' => $counts['update'] ?? 0,
            'deleted' => $counts['delete'] ?? 0,
            'undoable' => $undoable,
        ];
    }

    /**
     * Eine angewendete Änderung umkehren (D-09): in einer Transaktion, rückwärts, und nur, wenn die Zeilen noch so
     * aussehen wie nach der Änderung — sonst bleibt alles, wie es ist.
     *
     * @return array{rows: int}
     *
     * @throws ChangeRejected
     * @throws ChangeConflict
     */
    public function undo(DatabaseConnection $connection, User $user, int $auditId): array
    {
        $snapshots = ChangeSnapshot::query()
            ->where('audit_log_id', $auditId)
            ->where('user_id', $user->id)
            ->where('connection_id', $connection->id)
            ->whereNull('undone_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('position')
            ->get();

        if ($snapshots->isEmpty()) {
            throw new ChangeRejected(__('Diese Änderung lässt sich nicht mehr rückgängig machen.'));
        }

        /** @var ChangeSnapshot $first */
        $first = $snapshots->first();
        [, $primaryKey] = $this->describe($connection, $first->database, $first->schema, $first->target, []);

        $this->targets->withWritable($connection, function (Connection $writer) use ($snapshots, $first, $primaryKey): void {
            $writer->transaction(function () use ($writer, $snapshots, $first, $primaryKey): void {
                foreach ($snapshots as $snapshot) {
                    $current = $this->row($writer, $first->schema, $first->target, $primaryKey, $snapshot->key);

                    if ($snapshot->operation !== 'delete' && ($current === null || $this->portable($current) != $snapshot->after)) {
                        throw new ChangeConflict(__('Eine Zeile wurde seither geändert — Rückgängig würde diese Änderung überschreiben. Nichts wurde geändert.'));
                    }

                    if ($snapshot->operation === 'delete' && $current !== null) {
                        throw new ChangeConflict(__('Eine gelöschte Zeile gibt es inzwischen wieder. Nichts wurde geändert.'));
                    }

                    $query = $this->keyed($writer->table($first->schema.'.'.$first->target), $primaryKey, $snapshot->key);

                    match ($snapshot->operation) {
                        'insert' => $query->delete(),
                        'update' => $query->update($this->restorable($snapshot->before ?? [])),
                        'delete' => $writer->table($first->schema.'.'.$first->target)->insert($this->restorable($snapshot->before ?? [])),
                    };
                }
            });
        }, $first->database);

        ChangeSnapshot::query()->where('audit_log_id', $auditId)->update(['undone_at' => now()]);
        $this->audit->record('data.undo', $user, $first->schema.'.'.$first->target, [
            'connection_id' => $connection->id,
            'undone_audit_id' => $auditId,
            'affected_rows' => $snapshots->count(),
        ]);

        return ['rows' => $snapshots->count()];
    }

    /**
     * Die letzte eigene Änderung an dieser Tabelle, die sich noch umkehren lässt.
     *
     * @return array{auditId: int, at: string|null, rows: int}|null
     */
    public function lastUndoable(DatabaseConnection $connection, User $user, string $schema, string $table): ?array
    {
        $latest = ChangeSnapshot::query()
            ->where('user_id', $user->id)
            ->where('connection_id', $connection->id)
            ->where('schema', $schema)
            ->where('target', $table)
            ->whereNull('undone_at')
            ->where('expires_at', '>', now())
            ->max('audit_log_id');

        if ($latest === null) {
            return null;
        }

        return [
            'auditId' => (int) $latest,
            'at' => AuditEntry::query()->find((int) $latest)?->created_at->toIso8601String(),
            'rows' => ChangeSnapshot::query()->where('audit_log_id', $latest)->count(),
        ];
    }

    /**
     * Spalten und Primärschlüssel; prüft die Änderungen gegen die Tabelle (D-07: ohne Primärschlüssel nur lesen).
     *
     * @param  list<Change>  $changes
     * @return array{0: array<string, GridColumn>, 1: list<string>}
     *
     * @throws ChangeRejected
     */
    private function describe(DatabaseConnection $connection, ?string $database, string $schema, string $table, array $changes): array
    {
        $columns = $this->data->columns($connection, $schema, $table, $database);

        if ($columns === null) {
            throw new ChangeRejected(__('Diese Tabelle gibt es nicht.'));
        }

        $primaryKey = array_values(array_map(fn (array $column): string => $column['name'], array_filter($columns, fn (array $column): bool => $column['primary'])));

        if ($primaryKey === []) {
            throw new ChangeRejected(__('Tabellen ohne Primärschlüssel lassen sich nur lesen — eine Zeile wäre nicht eindeutig zu treffen.'));
        }

        if (count($changes) > self::MAX_CHANGES) {
            throw new ChangeRejected(__('Höchstens :count Änderungen auf einmal.', ['count' => self::MAX_CHANGES]));
        }

        $byName = array_column($columns, null, 'name');

        foreach ($changes as $change) {
            if ($change['op'] !== 'insert' && count($change['key'] ?? []) !== count($primaryKey)) {
                throw new ChangeRejected(__('Eine Änderung nennt ihre Zeile nicht eindeutig.'));
            }

            foreach ($change['values'] ?? [] as $name => $value) {
                $column = $byName[$name] ?? null;

                if ($column === null) {
                    throw new ChangeRejected(__('Die Spalte «:column» gibt es in dieser Tabelle nicht.', ['column' => $name]));
                }

                if ($column['kind'] === ColumnKind::Json->value && is_string($value) && ! json_validate($value)) {
                    throw new ChangeRejected(__('«:column» enthält kein gültiges JSON.', ['column' => $name]));
                }

                if ($column['kind'] === ColumnKind::Binary->value && $value !== null) {
                    throw new ChangeRejected(__('Binärdaten lassen sich hier nur auf NULL setzen.'));
                }
            }

            if ($change['op'] === 'update' && ($change['values'] ?? []) === []) {
                throw new ChangeRejected(__('Eine Änderung ändert nichts.'));
            }
        }

        return [$byName, $primaryKey];
    }

    /**
     * Die Anweisung einer Änderung über den Query-Builder — für die Vorschau unter `pretend()`.
     *
     * @param  Change  $change
     * @param  list<string>  $primaryKey
     * @param  array<string, GridColumn>  $columns
     */
    private function statement(Connection $connection, string $schema, string $table, array $primaryKey, array $change, array $columns): void
    {
        $query = $connection->table($schema.'.'.$table);
        $values = $this->writable($change['values'] ?? [], $columns, $connection);

        match ($change['op']) {
            'insert' => $query->insert($values),
            'update' => $this->keyed($query, $primaryKey, $change['key'] ?? [])->update($values),
            'delete' => $this->keyed($query, $primaryKey, $change['key'] ?? [])->delete(),
        };
    }

    /**
     * @param  Change  $change
     * @param  list<string>  $primaryKey
     * @param  array<string, GridColumn>  $columns
     */
    private function execute(Connection $connection, string $schema, string $table, array $primaryKey, array $change, array $columns): int
    {
        $query = $this->keyed($connection->table($schema.'.'.$table), $primaryKey, $change['key'] ?? []);

        return $change['op'] === 'delete' ? $query->delete() : $query->update($this->writable($change['values'] ?? [], $columns, $connection));
    }

    /**
     * Einfügen und den Schlüssel der neuen Zeile zurückgeben — selbst vergeben (Auto-Increment) oder aus den Werten.
     *
     * @param  list<string>  $primaryKey
     * @param  array<string, string|int|float|null>  $values
     * @return list<string|null>
     */
    private function insert(Connection $connection, string $schema, string $table, array $primaryKey, array $values): array
    {
        $query = $connection->table($schema.'.'.$table);

        if (count($primaryKey) === 1 && ! array_key_exists($primaryKey[0], $values)) {
            return [Cells::key($query->insertGetId($values, $primaryKey[0]))];
        }

        $query->insert($values);

        return array_map(fn (string $column): ?string => Cells::key($values[$column] ?? null), $primaryKey);
    }

    /**
     * Werte so, wie der Treiber sie annimmt: Wahrheitswerte als 1/0 (PostgreSQL liest das auch für boolean).
     *
     * @param  array<string, string|int|float|bool|null>  $values
     * @param  array<string, GridColumn>  $columns
     * @return array<string, string|int|float|null>
     */
    private function writable(array $values, array $columns, Connection $connection): array
    {
        return array_map(fn (mixed $value): mixed => is_bool($value)
            ? ($connection->getDriverName() === 'pgsql' ? ($value ? 'true' : 'false') : (int) $value)
            : $value, $values);
    }

    /**
     * @param  list<string>  $primaryKey
     * @param  list<string|null>  $key
     */
    private function keyed(Builder $query, array $primaryKey, array $key): Builder
    {
        foreach ($primaryKey as $position => $column) {
            $query->where($column, '=', $key[$position] ?? null);
        }

        return $query;
    }

    /**
     * @param  list<string>  $primaryKey
     * @param  list<string|null>  $key
     * @return array<string, mixed>|null
     */
    private function row(Connection $connection, string $schema, string $table, array $primaryKey, array $key): ?array
    {
        $row = $this->keyed($connection->table($schema.'.'.$table), $primaryKey, $key)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Zeile so, dass sie als JSON gespeichert und genau so wieder geschrieben werden kann: Binärdaten als Base64,
     * Ströme gelesen.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function portable(array $row): array
    {
        return array_map(function (mixed $value): mixed {
            if (is_resource($value)) {
                $value = (string) stream_get_contents($value);
            }

            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                return ['$base64' => base64_encode($value)];
            }

            return $value;
        }, $row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function restorable(array $row): array
    {
        return array_map(fn (mixed $value): mixed => is_array($value) && isset($value['$base64']) ? base64_decode((string) $value['$base64']) : $value, $row);
    }

    /**
     * SQL mit eingesetzten Werten — nur zur Anzeige in der Vorschau, ausgeführt wird mit Bindungen.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function display(Connection $connection, string $sql, array $bindings): string
    {
        try {
            return $connection->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $connection->prepareBindings($bindings));
        } catch (Throwable) {
            return $sql;
        }
    }

    /**
     * Zeilen, die ein DELETE über ON DELETE CASCADE mitlöschen würde (erste Ebene).
     *
     * @param  list<string>  $primaryKey
     * @param  list<string|null>  $key
     * @param  list<array{name: string, table: string, columns: list<string>, foreignColumns: list<string>, onDelete: string}>  $cascading
     * @return list<array{table: string, count: int}>
     */
    private function cascades(DatabaseConnection $connection, Connection $target, string $schema, string $table, array $primaryKey, array $key, array $cascading): array
    {
        if ($cascading === []) {
            return [];
        }

        $row = $this->row($target, $schema, $table, $primaryKey, $key);

        if ($row === null) {
            return [];
        }

        $counts = [];
        foreach ($cascading as $reference) {
            $query = $target->table($schema.'.'.$reference['table']);
            foreach ($reference['columns'] as $position => $column) {
                $query->where($column, '=', $row[$reference['foreignColumns'][$position]] ?? null);
            }

            $count = $connection->driver->dialect()->runReadOnly($target, fn (): int => $query->count());

            if ($count > 0) {
                $counts[] = ['table' => $reference['table'], 'count' => $count];
            }
        }

        return $counts;
    }
}
