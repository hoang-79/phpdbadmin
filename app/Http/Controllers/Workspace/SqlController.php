<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Connections\WriteUnlock;
use App\Enums\ConnectionEnvironment;
use App\Enums\DatabaseDriver;
use App\Http\Requests\Workspace\RunSqlRequest;
use App\Models\DatabaseConnection;
use App\Models\QueryHistoryEntry;
use App\Models\SavedQuery;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Catalog\Catalog;
use App\Services\Sql\QueryRunner;
use App\Services\Sql\StatementClassifier;
use App\Services\Sql\StatementKind;
use App\Services\Sql\StatementSplitter;
use App\Support\SqlMask;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;

/**
 * SQL-Editor (Q-01 bis Q-04, Q-07, Q-09, Wireframe 10): Seite mit Vervollständigung aus dem Schema, Ausführen mit
 * Grenzen, Abbrechen, Verlauf und gespeicherten Abfragen. Gefährliche Anweisungen verlangen eine Bestätigung, in
 * Produktion zusätzlich den Namen der Verbindung.
 */
class SqlController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        private readonly StatementSplitter $splitter,
        private readonly StatementClassifier $classifier,
        private readonly QueryRunner $runner,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($catalog);
    }

    public function show(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection): Response {
            $database = $this->database($request, $connection);
            $schemas = $this->catalog->schemas($connection, $database);
            $requested = $request->query('schema');
            $schema = is_string($requested) && in_array($requested, $schemas, true) ? $requested : $this->catalog->defaultSchema($connection, $database);
            $user = $this->user($request);

            return $this->render($request, $connection, 'workspace/Sql', $schemas, $schema, $schema === null ? [] : $this->catalog->groups($connection, $schema, $database), $database, [
                'queryTab' => max(1, min(99, (int) $request->query('tab', '1'))),
                // Vorlage aus dem Reiter «SQL» eines Objekts — nur in einen leeren Editor übernommen.
                'template' => is_string($request->query('vorlage')) ? mb_substr((string) $request->query('vorlage'), 0, 10000) : null,
                'editor' => [
                    'timeoutSeconds' => (int) config('phpdbadmin.editor.timeout_seconds', 30),
                    'rowLimit' => (int) config('phpdbadmin.editor.row_limit', 1000),
                    'maxRowLimit' => (int) config('phpdbadmin.editor.max_row_limit', 10000),
                    'canWrite' => (! $connection->read_only || app(WriteUnlock::class)->isActive($connection)) && Gate::forUser($user)->allows('write', $connection),
                ],
                'saved' => SavedQuery::query()
                    ->where('user_id', $user->id)
                    ->where('connection_id', $connection->id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'sql', 'updated_at'])
                    ->map(fn (SavedQuery $query): array => ['id' => $query->id, 'name' => $query->name, 'sql' => $query->sql, 'updatedAt' => $query->updated_at?->toIso8601String()]),
                'history' => $this->history($user, $connection),
                'completion' => Inertia::defer(fn (): array => $schema === null ? [] : $this->catalog->completion($connection, $schema, $database)),
            ]);
        });
    }

    public function run(RunSqlRequest $request, DatabaseConnection $connection): JsonResponse
    {
        $user = $this->user($request);
        /** @var array{sql: string, mode: 'statement'|'all', cursor?: int|null, run: string, limit?: int|null, schema?: string|null, confirmed?: bool, confirmation?: string|null} $input */
        $input = $request->validated();
        $driver = $connection->driver;

        $atCursor = $this->splitter->statementAt($input['sql'], (int) ($input['cursor'] ?? strlen($input['sql'])), $driver);
        $statements = $input['mode'] === 'statement'
            ? ($atCursor === null ? [] : [$atCursor])
            : $this->splitter->split($input['sql'], $driver);
        $texts = array_column($statements, 'sql');

        if ($texts === []) {
            return response()->json(['reason' => 'empty'], 422);
        }

        $dangers = array_values(array_filter(array_map(fn (string $sql): ?array => ($reason = $this->classifier->danger($sql)) === null ? null : ['sql' => $sql, 'reason' => $reason], $texts)));
        $production = $connection->environment === ConnectionEnvironment::Production;

        if ($dangers !== [] && ! ($input['confirmed'] ?? false)) {
            return response()->json(['reason' => 'confirm', 'dangers' => $dangers, 'production' => $production], 409);
        }

        if ($dangers !== [] && $production && ($input['confirmation'] ?? null) !== $connection->name) {
            return response()->json(['reason' => 'confirm', 'dangers' => $dangers, 'production' => true, 'wrongName' => true], 409);
        }

        $schema = $input['schema'] ?? null;
        $database = match (true) {
            $driver === DatabaseDriver::Postgres => $this->database($request, $connection),
            $connection->database === null && $driver !== DatabaseDriver::Sqlite => $schema,
            default => null,
        };

        try {
            $results = $this->runner->run(
                $connection,
                $database,
                $texts,
                $input['run'],
                Gate::forUser($user)->allows('write', $connection),
                (int) ($input['limit'] ?? config('phpdbadmin.editor.row_limit', 1000)),
                $user->id,
                $driver === DatabaseDriver::Postgres ? $schema : null,
            );
        } catch (PasswordRequired) {
            return response()->json(['reason' => 'password'], 409);
        } catch (ConnectionNotAllowed $exception) {
            return response()->json(['reason' => 'connection', 'problem' => $exception->problem->toArray()], 503);
        } catch (QueryException|PDOException $exception) {
            if (! $this->isTargetError($exception)) {
                throw $exception;
            }

            return response()->json(['reason' => 'connection', 'problem' => $driver->dialect()->describeConnectionError($exception)->toArray()], 503);
        }

        foreach ($results as $result) {
            if ($result['status'] === 'skipped') {
                continue;
            }

            QueryHistoryEntry::query()->create([
                'user_id' => $user->id,
                'connection_id' => $connection->id,
                'sql' => $result['sql'],
                'status' => $result['status'],
                'duration_ms' => (int) round($result['durationMs']),
                'row_count' => $result['affected'] ?? ($result['columns'] === [] ? null : count($result['rows'])),
            ]);

            if ($result['status'] === 'ok' && $this->classifier->kind($result['sql']) === StatementKind::Write) {
                $this->audit->record('sql.execute', $user, $connection->name, [
                    'connection_id' => $connection->id,
                    'affected_rows' => $result['affected'],
                ], SqlMask::mask($result['sql']));
            }
        }

        return response()->json(['results' => $results, 'history' => $this->history($user, $connection)]);
    }

    public function cancel(Request $request, DatabaseConnection $connection): HttpResponse
    {
        $run = (string) $request->validate(['run' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']])['run'];
        $this->runner->cancel($connection, $run, $this->user($request)->id);

        return response()->noContent();
    }

    /**
     * Verlauf durchsuchen (Q-04) — nur der eigene, nur diese Verbindung.
     */
    public function searchHistory(Request $request, DatabaseConnection $connection): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        return response()->json($this->history($this->user($request), $connection, $term === '' ? null : $term));
    }

    public function save(Request $request, DatabaseConnection $connection): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sql' => ['required', 'string', 'max:1000000'],
        ]);

        $saved = SavedQuery::query()->updateOrCreate(
            ['user_id' => $this->user($request)->id, 'connection_id' => $connection->id, 'name' => $validated['name']],
            ['sql' => $validated['sql']],
        );

        return response()->json(['id' => $saved->id, 'name' => $saved->name, 'sql' => $saved->sql, 'updatedAt' => $saved->updated_at?->toIso8601String()], 201);
    }

    public function forget(Request $request, DatabaseConnection $connection, SavedQuery $query): HttpResponse
    {
        abort_unless($query->user_id === $this->user($request)->id && $query->connection_id === $connection->id, 404);
        $query->delete();

        return response()->noContent();
    }

    /**
     * @return list<array{id: int, sql: string, status: string, durationMs: int|null, rows: int|null, at: string|null}>
     */
    private function history(User $user, DatabaseConnection $connection, ?string $term = null): array
    {
        return array_values(QueryHistoryEntry::query()
            ->where('user_id', $user->id)
            ->where('connection_id', $connection->id)
            ->when($term !== null, fn ($query) => $query->where('sql', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $term).'%'))
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(fn (QueryHistoryEntry $entry): array => [
                'id' => $entry->id,
                'sql' => $entry->sql,
                'status' => $entry->status,
                'durationMs' => $entry->duration_ms,
                'rows' => $entry->row_count,
                'at' => $entry->created_at?->toIso8601String(),
            ])
            ->all());
    }
}
