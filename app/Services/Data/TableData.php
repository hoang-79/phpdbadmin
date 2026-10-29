<?php

namespace App\Services\Data;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionProblemKind;
use App\Connections\TargetConnections;
use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use App\Enums\FilterOperator;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Structure;
use App\Support\TrustedSql;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/**
 * Daten einer Tabelle oder Sicht für das Raster (D-01, D-02, D-03, D-06, N-01). Blättert über den Primärschlüssel,
 * wo die Sortierung es erlaubt, sonst über den Versatz. NULL steht in beiden Richtungen zuletzt — ausdrücklich, nicht
 * nach Gewohnheit des Treibers. Werte des Filters werden immer gebunden; alles läuft im Schreibschutz-Wächter.
 *
 * @phpstan-import-type Cell from Cells
 *
 * @phpstan-type GridColumn array{name: string, type: string, kind: string, kindLabel: string, nullable: bool, primary: bool, references: array{table: string, column: string}|null}
 */
final class TableData
{
    /** Bevorzugte Spalten für die Beschriftung eines Fremdschlüssels («→ 5528 Beat Keller»). */
    private const array LABEL_COLUMNS = ['name', 'title', 'label', 'bezeichnung', 'display_name', 'full_name', 'email', 'username', 'slug', 'code'];

    /** @var array<string, list<GridColumn>> */
    private array $columns = [];

    public function __construct(private readonly TargetConnections $targets) {}

    /**
     * Spalten in der Reihenfolge der Tabelle, null für ein unbekanntes Objekt.
     *
     * @return list<GridColumn>|null
     */
    public function columns(DatabaseConnection $connection, string $schema, string $object, ?string $database = null): ?array
    {
        $cacheKey = $connection->id.'|'.$database.'|'.$schema.'|'.$object;

        if (isset($this->columns[$cacheKey])) {
            return $this->columns[$cacheKey];
        }

        $target = $this->targets->open($connection, $database);
        $builder = $target->getSchemaBuilder();
        $qualified = $schema.'.'.$object;
        $raw = $builder->getColumns($qualified);

        if ($raw === []) {
            return null;
        }

        $isView = in_array($object, array_column($builder->getViews($schema), 'name'), true);
        $primary = $isView ? [] : (array_find($builder->getIndexes($qualified), fn (array $index): bool => (bool) $index['primary'])['columns'] ?? []);
        $references = [];

        foreach ($isView ? [] : $builder->getForeignKeys($qualified) as $foreignKey) {
            if (count($foreignKey['columns']) === 1) {
                $references[$foreignKey['columns'][0]] = ['table' => (string) $foreignKey['foreign_table'], 'column' => (string) $foreignKey['foreign_columns'][0]];
            }
        }

        // MariaDB kennt JSON nur als longtext mit json_valid-Check — die Art folgt dem Check (wie in der Struktur).
        $jsonColumns = $connection->driver === DatabaseDriver::MariaDb && ! $isView
            ? Structure::jsonCheckedColumns($connection->driver->dialect()->checkConstraints($target, $schema, $object))
            : [];

        $columns = [];
        foreach ($raw as $column) {
            $kind = in_array($column['name'], $jsonColumns, true) ? ColumnKind::Json : ColumnKind::of((string) $column['type_name'], (string) $column['type']);
            $columns[] = [
                'name' => (string) $column['name'],
                'type' => (string) $column['type'],
                'kind' => $kind->value,
                'kindLabel' => $kind->label(),
                'nullable' => (bool) $column['nullable'],
                'primary' => in_array($column['name'], $primary, true),
                'references' => $references[$column['name']] ?? null,
            ];
        }

        return $this->columns[$cacheKey] = $columns;
    }

    /**
     * Eine Seite des Rasters.
     *
     * @return array{columns: list<GridColumn>, primaryKey: list<string>, rows: list<list<Cell>>, keys: list<list<string|null>>, hasMore: bool, next: array{after?: list<string|null>, offset?: int}|null, labels: array<string, array<string, string>>, total: array{count: int|null, exact: bool}, durationMs: float}|null
     *
     * @throws GridQueryFailed
     */
    public function page(DatabaseConnection $connection, string $schema, string $object, GridQuery $query, ?string $database = null): ?array
    {
        $columns = $this->columns($connection, $schema, $object, $database);

        if ($columns === null) {
            return null;
        }

        $target = $this->targets->open($connection, $database);
        $dialect = $connection->driver->dialect();
        $primaryKey = array_values(array_map(fn (array $column): string => $column['name'], array_filter($columns, fn (array $column): bool => $column['primary'])));
        [$order, $keyset] = $this->order($query, $columns, $primaryKey);

        $start = hrtime(true);
        $records = $this->run($connection, $target, function () use ($target, $schema, $object, $query, $columns, $order, $keyset, $primaryKey): array {
            $builder = $this->filtered($target, $schema, $object, $query, $columns);

            foreach ($order as [$column, $direction, $nullable]) {
                if ($nullable) {
                    // Gequoteter Spaltenname aus der Tabelle selbst, kein Wert der Person.
                    $builder->orderBy(new TrustedSql('case when '.$target->getQueryGrammar()->wrap($column).' is null then 1 else 0 end'));
                }
                $builder->orderBy($column, $direction);
            }

            if ($keyset !== null && $query->after !== null && count($query->after) === count($primaryKey)) {
                $this->after($builder, $primaryKey, $query->after, $keyset);
            } elseif ($keyset === null && $query->offset > 0) {
                $builder->offset($query->offset);
            }

            return $builder->limit($query->limit + 1)->get()->map(fn (object $row): array => (array) $row)->all();
        });
        $durationMs = round((hrtime(true) - $start) / 1e6, 1);

        $hasMore = count($records) > $query->limit;
        $records = array_slice($records, 0, $query->limit);
        $kinds = array_map(fn (array $column): ColumnKind => ColumnKind::from($column['kind']), $columns);

        $rows = [];
        $keys = [];
        foreach ($records as $record) {
            $values = array_values($record);
            $rows[] = array_map(fn (int $index): mixed => Cells::encode($values[$index] ?? null, $kinds[$index]), array_keys($columns));
            $keys[] = array_map(fn (string $name): ?string => Cells::key($record[$name] ?? null), $primaryKey);
        }

        $next = null;
        if ($hasMore) {
            $next = $keyset !== null ? ['after' => $keys[array_key_last($keys)] ?? []] : ['offset' => $query->offset + $query->limit];
        }

        $isFirstPage = $query->after === null && $query->offset === 0;

        return [
            'columns' => $columns,
            'primaryKey' => $primaryKey,
            'rows' => $rows,
            'keys' => $keys,
            'hasMore' => $hasMore,
            'next' => $next,
            'labels' => $this->labels($target, $schema, $columns, $records),
            'total' => $isFirstPage && ! $hasMore
                ? ['count' => count($rows), 'exact' => true]
                : ($isFirstPage ? $this->total($connection, $target, $schema, $object, $query, $columns) : ['count' => null, 'exact' => false]),
            'durationMs' => $durationMs,
        ];
    }

    /**
     * Genau zählen auf Wunsch (D-06) — mit denselben Filtern, unter dem Zeitlimit der Verbindung.
     *
     * @throws GridQueryFailed
     */
    public function count(DatabaseConnection $connection, string $schema, string $object, GridQuery $query, ?string $database = null): ?int
    {
        $columns = $this->columns($connection, $schema, $object, $database);

        if ($columns === null) {
            return null;
        }

        $target = $this->targets->open($connection, $database);

        return $this->run($connection, $target, fn (): int => $this->filtered($target, $schema, $object, $query, $columns)->count());
    }

    /**
     * Abfrage mit Filtern, freiem WHERE und Suche — ohne Sortierung und Blättern.
     *
     * @param  list<GridColumn>  $columns
     *
     * @throws GridQueryFailed
     */
    private function filtered(Connection $target, string $schema, string $object, GridQuery $query, array $columns): Builder
    {
        $builder = $target->table($schema.'.'.$object);
        $byName = array_column($columns, null, 'name');
        $isPostgres = $target->getDriverName() === 'pgsql';

        if ($query->filters !== []) {
            $builder->where(function (Builder $group) use ($query, $byName, $target, $isPostgres): void {
                foreach ($query->filters as $filter) {
                    if (! isset($byName[$filter['column']])) {
                        throw new GridQueryFailed('column', __('Die Spalte «:column» gibt es in dieser Tabelle nicht.', ['column' => $filter['column']]));
                    }

                    $this->condition($group, $target, $filter['column'], $filter['operator'], $filter['value'], $filter['boolean'], $isPostgres);
                }
            });
        }

        if ($query->where !== null) {
            // Freies WHERE ist absichtlich SQL der Person (D-02): nur lesend im Wächter, eine Anweisung, Zeitlimit.
            $builder->whereRaw(new TrustedSql('('.$query->where.')'));
        }

        if ($query->search !== null) {
            $searchable = array_filter($columns, fn (array $column): bool => in_array($column['kind'], ['text', 'choice', 'json', 'identifier'], true));

            if ($searchable === []) {
                throw new GridQueryFailed('search', __('Diese Tabelle hat keine Textspalten zum Durchsuchen.'));
            }

            $builder->where(function (Builder $group) use ($searchable, $query, $target, $isPostgres): void {
                foreach ($searchable as $column) {
                    $this->condition($group, $target, $column['name'], FilterOperator::Contains, (string) $query->search, 'or', $isPostgres);
                }
            });
        }

        return $builder;
    }

    /**
     * Eine Bedingung des geführten Filters. «enthält» und Verwandte: PostgreSQL mit ILIKE auf den Text der Spalte,
     * MySQL und MariaDB nach der Kollation (meist ohne Gross-/Kleinschreibung und Akzente), SQLite über die
     * Faltfunktion aus der ConnectionFactory (Unicode-Kleinschreibung).
     * Platzhalter im Wert gelten als Zeichen (Escape mit «!»).
     *
     * @param  'and'|'or'  $boolean
     */
    private function condition(Builder $builder, Connection $target, string $column, FilterOperator $operator, ?string $value, string $boolean, bool $isPostgres): void
    {
        $wrapped = $target->getQueryGrammar()->wrap($column);

        if (in_array($operator, [FilterOperator::Contains, FilterOperator::NotContains, FilterOperator::StartsWith, FilterOperator::EndsWith], true)) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $value);
            $pattern = match ($operator) {
                FilterOperator::StartsWith => $escaped.'%',
                FilterOperator::EndsWith => '%'.$escaped,
                default => '%'.$escaped.'%',
            };
            $expression = match ($target->getDriverName()) {
                'pgsql' => 'cast('.$wrapped.' as text) ilike ?',
                'sqlite' => ConnectionFactory::SQLITE_FOLD.'('.$wrapped.') like '.ConnectionFactory::SQLITE_FOLD.'(?)',
                default => $wrapped.' like ?',
            };
            $sql = ($operator === FilterOperator::NotContains ? 'not ('.$expression." escape '!')" : $expression." escape '!'");

            if ($operator === FilterOperator::NotContains) {
                $sql = '('.$wrapped.' is null or '.$sql.')';
            }

            $builder->whereRaw(new TrustedSql($sql), [$pattern], $boolean);

            return;
        }

        match ($operator) {
            FilterOperator::IsNull => $builder->whereNull($column, $boolean),
            FilterOperator::IsNotNull => $builder->whereNotNull($column, $boolean),
            default => $builder->where($column, match ($operator) {
                FilterOperator::NotEquals => '<>',
                FilterOperator::LessThan => '<',
                FilterOperator::LessOrEqual => '<=',
                FilterOperator::GreaterThan => '>',
                FilterOperator::GreaterOrEqual => '>=',
                default => '=',
            }, $value, $boolean),
        };
    }

    /**
     * Sortierung mit dem Primärschlüssel als letztem Kriterium (stabile Seiten). Blättern über den Schlüssel geht,
     * wenn nur nach dem Primärschlüssel in einer Richtung sortiert wird; sonst Versatz.
     *
     * @param  list<GridColumn>  $columns
     * @param  list<string>  $primaryKey
     * @return array{0: list<array{0: string, 1: 'asc'|'desc', 2: bool}>, 1: 'asc'|'desc'|null}
     *
     * @throws GridQueryFailed
     */
    private function order(GridQuery $query, array $columns, array $primaryKey): array
    {
        $byName = array_column($columns, null, 'name');
        $order = [];

        foreach ($query->sort as $sort) {
            if (! isset($byName[$sort['column']])) {
                throw new GridQueryFailed('column', __('Die Spalte «:column» gibt es in dieser Tabelle nicht.', ['column' => $sort['column']]));
            }

            if (! in_array($sort['column'], array_column($order, 0), true)) {
                $order[] = [$sort['column'], $sort['direction'], $byName[$sort['column']]['nullable']];
            }
        }

        $direction = $order[0][1] ?? 'asc';
        foreach ($primaryKey as $column) {
            if (! in_array($column, array_column($order, 0), true)) {
                $order[] = [$column, $direction, false];
            }
        }

        $onlyPrimary = $primaryKey !== []
            && array_column($order, 0) === $primaryKey
            && count(array_unique(array_column($order, 1))) === 1;

        return [$order, $onlyPrimary ? $direction : null];
    }

    /**
     * Nach der letzten Zeile weiter: (a, b) > (x, y) ausgeschrieben, weil nicht jede Version Zeilenwerte vergleicht.
     *
     * @param  list<string>  $primaryKey
     * @param  list<string|int|float|null>  $after
     * @param  'asc'|'desc'  $direction
     */
    private function after(Builder $builder, array $primaryKey, array $after, string $direction): void
    {
        $operator = $direction === 'asc' ? '>' : '<';

        $builder->where(function (Builder $outer) use ($primaryKey, $after, $operator): void {
            foreach (array_keys($primaryKey) as $depth) {
                $outer->orWhere(function (Builder $inner) use ($primaryKey, $after, $operator, $depth): void {
                    for ($position = 0; $position < $depth; $position++) {
                        $inner->where($primaryKey[$position], '=', $after[$position]);
                    }
                    $inner->where($primaryKey[$depth], $operator, $after[$depth]);
                });
            }
        });
    }

    /**
     * Gesamtzahl für die erste Seite: geschätzt bei grossen Tabellen ohne Filter, genau bei kleinen; mit Filter auf
     * einer grossen Tabelle offen («genau zählen» auf Wunsch).
     *
     * @param  list<GridColumn>  $columns
     * @return array{count: int|null, exact: bool}
     */
    private function total(DatabaseConnection $connection, Connection $target, string $schema, string $object, GridQuery $query, array $columns): array
    {
        $estimate = array_find($connection->driver->dialect()->tables($target, $schema), fn (array $table): bool => $table['name'] === $object)['rows'] ?? null;

        if ($estimate !== null && $estimate > (int) config('phpdbadmin.data.exact_count_limit', 50000)) {
            return $query->isFiltered() ? ['count' => null, 'exact' => false] : ['count' => $estimate, 'exact' => false];
        }

        return ['count' => $this->run($connection, $target, fn (): int => $this->filtered($target, $schema, $object, $query, $columns)->count()), 'exact' => true];
    }

    /**
     * Beschriftungen für einspaltige Fremdschlüssel der Seite: «5528» → «Beat Keller» (Wireframe 05).
     *
     * @param  list<GridColumn>  $columns
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, string>>
     */
    private function labels(Connection $target, string $schema, array $columns, array $records): array
    {
        $labels = [];

        foreach ($columns as $column) {
            if ($column['references'] === null || $records === []) {
                continue;
            }

            $values = array_values(array_unique(array_filter(array_map(fn (array $record): ?string => Cells::key($record[$column['name']] ?? null), $records), fn (?string $value): bool => $value !== null)));
            $referenced = $column['references'];
            $label = $this->labelColumn($target, $schema, $referenced['table'], $referenced['column']);

            if ($values === [] || $label === null) {
                continue;
            }

            try {
                $found = $target->table($schema.'.'.$referenced['table'])
                    ->whereIn($referenced['column'], $values)
                    ->get([$referenced['column'].' as k', $label.' as l']);
            } catch (QueryException) {
                continue;
            }

            foreach ($found as $row) {
                $key = Cells::key($row->k ?? null);
                $text = is_scalar($row->l ?? null) ? (string) $row->l : null;

                if ($key !== null && $text !== null && $text !== '' && mb_check_encoding($text, 'UTF-8')) {
                    $labels[$column['name']][$key] = mb_strimwidth($text, 0, 80, '…');
                }
            }
        }

        return $labels;
    }

    /**
     * Spalte, die eine Zeile der Tabelle für Menschen benennt: bevorzugte Namen, sonst die erste Textspalte.
     */
    public function labelColumn(Connection $target, string $schema, string $table, string $keyColumn): ?string
    {
        $candidates = array_values(array_filter(
            $target->getSchemaBuilder()->getColumns($schema.'.'.$table),
            fn (array $column): bool => $column['name'] !== $keyColumn
                && ColumnKind::of((string) $column['type_name'], (string) $column['type']) === ColumnKind::Text,
        ));
        $names = array_map(fn (array $column): string => (string) $column['name'], $candidates);

        foreach (self::LABEL_COLUMNS as $preferred) {
            if (in_array($preferred, $names, true)) {
                return $preferred;
            }
        }

        return $names[0] ?? null;
    }

    /**
     * Im Schreibschutz-Wächter ausführen; SQL-Fehler und Zeitlimit werden zur Fehlerkarte, Verbindungsfehler
     * bleiben Verbindungsfehler.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     *
     * @throws GridQueryFailed
     */
    private function run(DatabaseConnection $connection, Connection $target, Closure $read): mixed
    {
        $dialect = $connection->driver->dialect();

        try {
            return $dialect->runReadOnly($target, $read);
        } catch (QueryException $exception) {
            if ($dialect->isTimeout($exception)) {
                throw new GridQueryFailed('timeout', __('Die Abfrage dauerte länger als erlaubt und wurde abgebrochen. Grenzen Sie mit einem Filter ein.'), $this->serverMessage($exception));
            }

            if ($dialect->describeConnectionError($exception)->kind !== ConnectionProblemKind::Unknown && $connection->driver !== DatabaseDriver::Sqlite) {
                throw $exception;
            }

            throw new GridQueryFailed('sql', __('Die Datenbank hat die Abfrage abgelehnt. Prüfen Sie die Bedingung.'), $this->serverMessage($exception));
        }
    }

    /**
     * Meldung des Servers ohne das SQL mit eingesetzten Werten, das Laravel anhängt.
     */
    private function serverMessage(QueryException $exception): string
    {
        return $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
    }
}
