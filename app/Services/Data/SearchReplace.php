<?php

namespace App\Services\Data;

use App\Connections\ConnectionFactory;
use App\Connections\TargetConnections;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Transfer\ColumnInfo;
use App\Support\TrustedSql;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Suchen und Ersetzen in einer Tabelle (D-08): in gewählten Textspalten, mit oder ohne Rücksicht auf Gross- und
 * Kleinschreibung, als Teiltext (jedes Vorkommen) oder ganzer Wert. Zuerst die Vorschau mit Zahl der Zeilen und
 * Beispielen vorher/nachher, dann Anwenden in **einer** Transaktion, mit Sicherung für Rückgängig bis zur Grenze
 * `phpdbadmin.data.undo_rows` (darüber nur nach ausdrücklicher Bestätigung) und genau einem Eintrag im Protokoll —
 * ohne Such- und Ersatztext.
 *
 * Die Datenbank sucht grob vor (LIKE mit Escape «!», ohne Rücksicht auf Gross- und Kleinschreibung — eine Obermenge
 * für jeden Treiber und jede Kollation), PHP entscheidet genau und ersetzt: so gelten dieselben Regeln auf allen vier
 * Datenbanken, auch für `%`, `_`, `\`, Umlaute und Emoji. Gelesen und geschrieben wird über den Primärschlüssel in
 * Paketen; ohne Primärschlüssel nur lesen (wie D-07).
 *
 * @phpstan-import-type GridColumn from TableData
 * @phpstan-import-type Snapshot from ChangeSnapshots
 *
 * @phpstan-type Replacement array{columns: list<string>, search: string, replace: string, matchCase: bool, wholeValue: bool}
 * @phpstan-type Sample array{key: list<string|null>, column: string, before: string, after: string}
 */
final class SearchReplace
{
    /** Zeilen je Paket beim Durchsuchen. */
    private const int CHUNK = 500;

    /** Beispiele in der Vorschau. */
    private const int SAMPLES = 10;

    /** Arten von Spalten, in denen gesucht und ersetzt wird — wie die Suche im Raster. */
    public const array KINDS = ['text', 'choice', 'json', 'identifier'];

    public function __construct(
        private readonly TargetConnections $targets,
        private readonly TableData $data,
        private readonly AuditLog $audit,
        private readonly ChangeSnapshots $snapshots,
    ) {}

    /** Höchstens so viele Zeilen ändert ein Ersetzen (sonst SQL-Editor). */
    public static function maxRows(): int
    {
        return (int) config('phpdbadmin.data.replace_rows', 10000);
    }

    /**
     * Spalten, in denen sich ersetzen lässt: Text, Auswahl, JSON, Kennung — nicht der Primärschlüssel, keine
     * generierten.
     *
     * @return list<GridColumn>
     */
    public function columns(DatabaseConnection $connection, ?string $database, string $schema, string $table): array
    {
        $columns = $this->data->columns($connection, $schema, $table, $database) ?? [];
        $generated = array_map(
            fn (ColumnInfo $column): string => $column->name,
            array_filter(ColumnInfo::of($this->targets->open($connection, $database), $connection->driver, $schema, $table), fn (ColumnInfo $column): bool => $column->generated),
        );

        return array_values(array_filter(
            $columns,
            fn (array $column): bool => in_array($column['kind'], self::KINDS, true) && ! $column['primary'] && ! in_array($column['name'], $generated, true),
        ));
    }

    /**
     * Vorschau: wie viele Zeilen und Zellen sich ändern würden, Beispiele vorher/nachher. Schreibt nichts.
     *
     * @param  Replacement  $replacement
     * @return array{rows: int, cells: int, samples: list<Sample>, undoable: bool, tooMany: bool, limit: int, undoLimit: int, sql: string}
     *
     * @throws ChangeRejected
     */
    public function preview(DatabaseConnection $connection, ?string $database, string $schema, string $table, array $replacement): array
    {
        [$columns, $primaryKey] = $this->describe($connection, $database, $schema, $table, $replacement);
        $target = $this->targets->open($connection, $database);
        $rows = 0;
        $cells = 0;
        $samples = [];

        $connection->driver->dialect()->runReadOnly($target, function () use ($target, $schema, $table, $columns, $primaryKey, $replacement, &$rows, &$cells, &$samples): void {
            foreach ($this->matches($target, $schema, $table, $columns, $primaryKey, $replacement, lock: false) as [$key, $row, $changed]) {
                $rows++;
                $cells += count($changed);

                foreach ($changed as $column => $after) {
                    if (count($samples) < self::SAMPLES) {
                        $samples[] = ['key' => $key, 'column' => $column, ...$this->excerpt((string) $row[$column], $after, $replacement)];
                    }
                }

                if ($rows > self::maxRows()) {
                    break;
                }
            }
        });

        return [
            'rows' => min($rows, self::maxRows()),
            'cells' => $cells,
            'samples' => $samples,
            'undoable' => $rows <= ChangeSnapshots::limit(),
            'tooMany' => $rows > self::maxRows(),
            'limit' => self::maxRows(),
            'undoLimit' => ChangeSnapshots::limit(),
            'sql' => $this->statement($target, $schema, $table, $primaryKey, $replacement['columns']),
        ];
    }

    /**
     * Anwenden in einer Transaktion. Über der Grenze für Rückgängig nur mit `$withoutUndo`, über `maxRows()` nie.
     *
     * @param  Replacement  $replacement
     * @return array{auditId: int, rows: int, cells: int, undoable: bool}
     *
     * @throws ChangeRejected
     * @throws ChangeConflict
     */
    public function apply(DatabaseConnection $connection, User $user, ?string $database, string $schema, string $table, array $replacement, bool $withoutUndo = false): array
    {
        [$columns, $primaryKey] = $this->describe($connection, $database, $schema, $table, $replacement);
        $statement = '';

        /** @var array{rows: int, cells: int, snapshots: list<Snapshot>|null, changedColumns: list<string>} $outcome */
        $outcome = $this->targets->withWritable($connection, fn (Connection $writer): array => $writer->transaction(function () use ($writer, $schema, $table, $columns, $primaryKey, $replacement, $withoutUndo, &$statement): array {
            $rows = 0;
            $cells = 0;
            $snapshots = [];
            $changedColumns = [];

            foreach ($this->matches($writer, $schema, $table, $columns, $primaryKey, $replacement, lock: true) as [$key, $row, $changed]) {
                $rows++;
                $cells += count($changed);
                $changedColumns += array_fill_keys(array_keys($changed), true);

                if ($rows > self::maxRows()) {
                    throw new ChangeRejected(__('Mehr als :limit Zeilen auf einmal ersetzt phpdbadmin nicht. Grenzen Sie die Spalten oder den Suchtext ein — oder nutzen Sie den SQL-Editor.', ['limit' => self::maxRows()]));
                }

                if ($rows > ChangeSnapshots::limit() && ! $withoutUndo) {
                    throw new ChangeRejected(__('Über :limit Zeilen lässt sich nicht rückgängig machen. Bestätigen Sie, dass Sie ohne Rückgängig ersetzen möchten.', ['limit' => ChangeSnapshots::limit()]));
                }

                $affected = $this->keyed($writer->table($schema.'.'.$table), $primaryKey, $key)->update($changed);

                if ($affected !== 1) {
                    throw new ChangeConflict(__('Eine Zeile liess sich nicht eindeutig ändern. Nichts wurde geändert.'));
                }

                if ($snapshots !== null && $rows <= ChangeSnapshots::limit()) {
                    $after = $this->keyed($writer->table($schema.'.'.$table), $primaryKey, $key)->first();
                    $snapshots[] = [
                        'position' => $rows - 1,
                        'operation' => 'update',
                        'key' => $key,
                        'before' => ChangeSnapshots::portable($row),
                        'after' => ChangeSnapshots::portable((array) $after),
                    ];
                } else {
                    // Über der Grenze nichts mehr im Speicher halten.
                    $snapshots = null;
                }
            }

            $statement = $this->statement($writer, $schema, $table, $primaryKey, array_keys($changedColumns));

            return ['rows' => $rows, 'cells' => $cells, 'snapshots' => $snapshots, 'changedColumns' => array_keys($changedColumns)];
        }), $database);

        $entry = $this->audit->record('data.replace', $user, $schema.'.'.$table, [
            'columns' => $outcome['changedColumns'],
            'whole_value' => $replacement['wholeValue'],
            'match_case' => $replacement['matchCase'],
            'cells' => $outcome['cells'],
        ], connection: $connection, sql: $statement, affectedRows: $outcome['rows']);

        $undoable = $outcome['snapshots'] !== null && $outcome['rows'] > 0
            && $this->snapshots->store($entry->id, $user, $connection, $database, $schema, $table, $outcome['snapshots']);

        return ['auditId' => $entry->id, 'rows' => $outcome['rows'], 'cells' => $outcome['cells'], 'undoable' => $undoable];
    }

    /**
     * Ersetzt in einem Wert — null, wenn nichts passt oder sich nichts ändert. Werte, die kein gültiges UTF-8 sind
     * (Binärdaten in einer Textspalte), bleiben unberührt.
     *
     * @param  Replacement  $replacement
     */
    public static function replaced(string $value, array $replacement): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $search = $replacement['search'];
        $replace = $replacement['replace'];
        $flags = $replacement['matchCase'] ? 'u' : 'iu';

        if ($replacement['wholeValue']) {
            $result = preg_match('/\A'.preg_quote($search, '/').'\z/'.$flags, $value) === 1 ? $replace : null;
        } elseif ($replacement['matchCase']) {
            $result = str_contains($value, $search) ? str_replace($search, $replace, $value) : null;
        } else {
            $result = preg_replace_callback('/'.preg_quote($search, '/').'/'.$flags, fn (): string => $replace, $value);
        }

        return $result === null || $result === $value ? null : $result;
    }

    /**
     * Zeilen, in denen sich etwas ändert: Schlüssel, ganze Zeile, neue Werte je Spalte. In Paketen über den
     * Primärschlüssel; beim Anwenden gesperrt (FOR UPDATE, SQLite sperrt ohnehin die ganze Datei).
     *
     * @param  list<GridColumn>  $columns
     * @param  list<string>  $primaryKey
     * @param  Replacement  $replacement
     * @return Generator<int, array{0: list<string|null>, 1: array<string, mixed>, 2: array<string, string>}>
     *
     * @throws ChangeRejected
     */
    private function matches(Connection $target, string $schema, string $table, array $columns, array $primaryKey, array $replacement, bool $lock): Generator
    {
        $after = null;
        $json = array_column(array_filter($columns, fn (array $column): bool => $column['kind'] === 'json'), 'name');

        while (true) {
            $query = $this->candidates($target, $schema, $table, $replacement);

            foreach ($primaryKey as $column) {
                $query->orderBy($column);
            }

            if ($after !== null) {
                $this->after($query, $primaryKey, $after);
            }

            if ($lock) {
                $query->lockForUpdate();
            }

            $records = $query->limit(self::CHUNK)->get();

            foreach ($records as $record) {
                $row = (array) $record;
                $changed = [];

                foreach ($replacement['columns'] as $column) {
                    $value = $row[$column] ?? null;
                    $new = is_string($value) ? self::replaced($value, $replacement) : null;

                    if ($new === null) {
                        continue;
                    }

                    if (in_array($column, $json, true) && ! json_validate($new)) {
                        throw new ChangeRejected(__('Nach dem Ersetzen wäre «:column» kein gültiges JSON mehr. Nichts wurde geändert.', ['column' => $column]));
                    }

                    $changed[$column] = $new;
                }

                if ($changed !== []) {
                    yield [array_map(fn (string $column): ?string => Cells::key($row[$column] ?? null), $primaryKey), $row, $changed];
                }
            }

            $last = $records->last();

            if ($last === null || $records->count() < self::CHUNK) {
                return;
            }

            $after = array_map(fn (string $column): mixed => ((array) $last)[$column] ?? null, $primaryKey);
        }
    }

    /**
     * Grobe Vorauswahl in der Datenbank: Spalte enthält den Suchtext (bzw. ist er, beim ganzen Wert) ohne Rücksicht
     * auf Gross- und Kleinschreibung. `%`, `_` und `!` gelten als Zeichen (Escape mit «!»), der Backslash sowieso.
     *
     * @param  Replacement  $replacement
     */
    private function candidates(Connection $target, string $schema, string $table, array $replacement): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $replacement['search']);
        $pattern = $replacement['wholeValue'] ? $escaped : '%'.$escaped.'%';
        $grammar = $target->getQueryGrammar();

        return $target->table($schema.'.'.$table)->where(function (Builder $group) use ($target, $grammar, $replacement, $pattern): void {
            foreach ($replacement['columns'] as $column) {
                $wrapped = $grammar->wrap($column);
                // Gequoteter Spaltenname aus der Tabelle selbst (geprüft in describe), der Suchtext bleibt gebunden.
                $expression = match ($target->getDriverName()) {
                    'pgsql' => 'cast('.$wrapped.' as text) ilike ?',
                    'sqlite' => ConnectionFactory::SQLITE_FOLD.'('.$wrapped.') like '.ConnectionFactory::SQLITE_FOLD.'(?)',
                    default => 'lower('.$wrapped.') like lower(?)',
                };
                $group->whereRaw(new TrustedSql($expression." escape '!'"), [$pattern], 'or');
            }
        });
    }

    /**
     * Spalten und Primärschlüssel prüfen.
     *
     * @param  Replacement  $replacement
     * @return array{0: list<GridColumn>, 1: list<string>}
     *
     * @throws ChangeRejected
     */
    private function describe(DatabaseConnection $connection, ?string $database, string $schema, string $table, array $replacement): array
    {
        $all = $this->data->columns($connection, $schema, $table, $database);

        if ($all === null) {
            throw new ChangeRejected(__('Diese Tabelle gibt es nicht.'));
        }

        $primaryKey = array_values(array_map(fn (array $column): string => $column['name'], array_filter($all, fn (array $column): bool => $column['primary'])));

        if ($primaryKey === []) {
            throw new ChangeRejected(__('Tabellen ohne Primärschlüssel lassen sich nur lesen — eine Zeile wäre nicht eindeutig zu treffen.'));
        }

        if ($replacement['search'] === '') {
            throw new ChangeRejected(__('Geben Sie einen Suchtext ein.'));
        }

        $usable = array_column($this->columns($connection, $database, $schema, $table), null, 'name');
        $chosen = [];

        foreach ($replacement['columns'] as $name) {
            if (! isset($usable[$name])) {
                throw new ChangeRejected(__('In der Spalte «:column» lässt sich nicht ersetzen — nur in Text-, Auswahl-, JSON- und Kennungsspalten ausserhalb des Primärschlüssels.', ['column' => $name]));
            }

            $chosen[] = $usable[$name];
        }

        if ($chosen === []) {
            throw new ChangeRejected(__('Wählen Sie mindestens eine Spalte.'));
        }

        return [$chosen, $primaryKey];
    }

    /**
     * Die Anweisung je Zeile ohne Werte — für die Vorschau und das Protokoll.
     *
     * @param  list<string>  $primaryKey
     * @param  list<string>  $columns
     */
    private function statement(Connection $connection, string $schema, string $table, array $primaryKey, array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        $queries = $connection->pretend(fn (Connection $pretending) => $this->keyed($pretending->table($schema.'.'.$table), $primaryKey, array_fill(0, count($primaryKey), null))
            ->update(array_fill_keys($columns, '')));

        return (string) ($queries[0]['query'] ?? '');
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
     * Nach dem letzten Schlüssel weiter: (a, b) > (x, y) ausgeschrieben.
     *
     * @param  list<string>  $primaryKey
     * @param  list<mixed>  $after
     */
    private function after(Builder $query, array $primaryKey, array $after): void
    {
        $query->where(function (Builder $outer) use ($primaryKey, $after): void {
            foreach (array_keys($primaryKey) as $depth) {
                $outer->orWhere(function (Builder $inner) use ($primaryKey, $after, $depth): void {
                    for ($position = 0; $position < $depth; $position++) {
                        $inner->where($primaryKey[$position], '=', $after[$position]);
                    }
                    $inner->where($primaryKey[$depth], '>', $after[$depth]);
                });
            }
        });
    }

    /**
     * Ausschnitt um die erste Stelle, die sich ändert — lange Texte nicht ganz.
     *
     * @param  Replacement  $replacement
     * @return array{before: string, after: string}
     */
    private function excerpt(string $before, string $after, array $replacement): array
    {
        $limit = 160;

        if (mb_strlen($before) <= $limit && mb_strlen($after) <= $limit) {
            return ['before' => $before, 'after' => $after];
        }

        $position = $replacement['matchCase'] ? mb_strpos($before, $replacement['search']) : mb_stripos($before, $replacement['search']);
        $start = max(0, (int) $position - 40);
        $prefix = $start > 0 ? '…' : '';

        return [
            'before' => $prefix.mb_strimwidth(mb_substr($before, $start), 0, $limit, '…'),
            'after' => $prefix.mb_strimwidth(mb_substr($after, $start), 0, $limit, '…'),
        ];
    }
}
