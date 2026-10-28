<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Enums\FilterOperator;
use App\Models\DatabaseConnection;
use App\Models\TableView;
use App\Models\User;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\Structure;
use App\Services\Data\GridQuery;
use App\Services\Data\GridQueryFailed;
use App\Services\Data\RowDetail;
use App\Services\Data\TableData;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Response;
use PDOException;

/**
 * Ein Objekt im Arbeitsbildschirm: Tabelle oder Sicht mit den Reitern Daten und Struktur (Wireframes 05, 07, 08).
 * Unbekannte Schemas und Objekte sind 404, Verbindungsfehler die Fehlerkarte; die JSON-Antworten des Rasters
 * melden dieselben Fälle mit Ursache, damit die Oberfläche sie erklären kann (O-09).
 */
class ObjectController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        private readonly Structure $structure,
        private readonly TableData $data,
        private readonly RowDetail $rows,
    ) {
        parent::__construct($catalog);
    }

    /**
     * Daten (D-01, D-02, N-01): die erste Seite kommt gleich mit der Seite, weitere über `rows`.
     */
    public function data(Request $request, DatabaseConnection $connection, string $schema, string $object): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection, $schema, $object): Response {
            [$database, $schemas] = $this->locate($request, $connection, $schema);
            $summary = $this->structure->summary($connection, $schema, $object, $database);
            abort_if($summary === null, 404);

            $query = GridQuery::fromRequest($request);
            $grid = null;
            $gridError = null;

            try {
                $grid = $this->data->page($connection, $schema, $object, $query, $database);
            } catch (GridQueryFailed $failed) {
                $gridError = $failed->toArray();
            }

            return $this->render($request, $connection, 'workspace/Data', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database, [
                'selected' => $object,
                'object' => $summary,
                'columns' => $this->data->columns($connection, $schema, $object, $database) ?? [],
                'grid' => $grid === null ? null : array_diff_key($grid, ['columns' => true]),
                'gridError' => $gridError,
                // Als Objekt: ein leeres PHP-Array würde zu «[]», und `[].sort` ist in JavaScript eine Methode.
                'query' => (object) $query->toQuery(),
                'operators' => array_map(fn (FilterOperator $operator): array => [
                    'value' => $operator->value,
                    'symbol' => $operator->symbol(),
                    'needsValue' => $operator->needsValue(),
                ], FilterOperator::cases()),
                'view' => $this->view($this->user($request), $connection, $database, $schema, $object),
            ]);
        });
    }

    /**
     * Struktur ansehen (S-01): Spalten mit Typ im Original und Art (O-04), Indizes, Fremdschlüssel in beide
     * Richtungen, Checks, Trigger, DDL.
     */
    public function structure(Request $request, DatabaseConnection $connection, string $schema, string $object): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection, $schema, $object): Response {
            [$database, $schemas] = $this->locate($request, $connection, $schema);
            $structure = $this->structure->describe($connection, $schema, $object, $database);
            abort_if($structure === null, 404);

            return $this->render($request, $connection, 'workspace/Structure', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database, [
                'selected' => $object,
                'structure' => $structure,
            ]);
        });
    }

    /**
     * Weitere Seiten des Rasters beim Scrollen.
     */
    public function rows(Request $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        return $this->json($connection, function () use ($request, $connection, $schema, $object): JsonResponse {
            [$database] = $this->locate($request, $connection, $schema);
            $page = $this->data->page($connection, $schema, $object, GridQuery::fromRequest($request), $database);
            abort_if($page === null, 404);

            return response()->json(array_diff_key($page, ['columns' => true]));
        });
    }

    /**
     * Genau zählen auf Wunsch (D-06).
     */
    public function count(Request $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        return $this->json($connection, function () use ($request, $connection, $schema, $object): JsonResponse {
            [$database] = $this->locate($request, $connection, $schema);
            $count = $this->data->count($connection, $schema, $object, GridQuery::fromRequest($request), $database);
            abort_if($count === null, 404);

            return response()->json(['count' => $count]);
        });
    }

    /**
     * Eine Zeile im Detail mit Verweisen in beide Richtungen (D-03, Wireframe 07).
     */
    public function row(Request $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        return $this->json($connection, function () use ($request, $connection, $schema, $object): JsonResponse {
            [$database] = $this->locate($request, $connection, $schema);
            $key = $request->query('key');
            abort_unless(is_array($key) && $key !== [], 404);

            $row = $this->rows->describe($connection, $schema, $object, array_values(array_map(fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null, $key)), $database);
            abort_if($row === null, 404);

            return response()->json($row);
        });
    }

    /**
     * Ausgeblendete Spalten und Breiten merken (D-01) — je Konto, nicht für alle.
     */
    public function saveView(Request $request, DatabaseConnection $connection, string $schema, string $object): HttpResponse
    {
        $validated = $request->validate([
            'hidden' => ['present', 'array', 'max:500'],
            'hidden.*' => ['string', 'max:255'],
            'widths' => ['present', 'array', 'max:500'],
            'widths.*' => ['integer', 'between:40,1200'],
        ]);
        $database = is_string($request->query('db')) ? $request->query('db') : null;

        TableView::query()->updateOrCreate([
            'user_id' => $this->user($request)->id,
            'connection_id' => $connection->id,
            'object_hash' => TableView::hashFor($database, $schema, $object),
        ], [
            'database' => $database,
            'schema' => $schema,
            'table' => $object,
            'columns' => ['hidden' => array_values($validated['hidden']), 'widths' => $validated['widths']],
        ]);

        return response()->noContent();
    }

    /**
     * Datenbank aus der Adresse und die Schemas der Verbindung; ein unbekanntes Schema ist 404.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function locate(Request $request, DatabaseConnection $connection, string $schema): array
    {
        $database = $this->database($request, $connection);
        $schemas = $this->catalog->schemas($connection, $database);
        abort_unless(in_array($schema, $schemas, true), 404);

        return [$database, $schemas];
    }

    /**
     * @return array{hidden: list<string>, widths: object}
     */
    private function view(User $user, DatabaseConnection $connection, ?string $database, string $schema, string $object): array
    {
        $columns = TableView::query()
            ->where('user_id', $user->id)
            ->where('connection_id', $connection->id)
            ->where('object_hash', TableView::hashFor($database, $schema, $object))
            ->value('columns');
        $columns = is_array($columns) ? $columns : (is_string($columns) ? json_decode($columns, true) : []);

        return [
            'hidden' => array_values(array_filter((array) ($columns['hidden'] ?? []), 'is_string')),
            'widths' => (object) array_filter((array) ($columns['widths'] ?? []), 'is_int'),
        ];
    }

    /**
     * Fehler der JSON-Antworten mit Ursache: Passwort fehlt (409), Abfrage abgelehnt (422), Verbindung weg (503).
     *
     * @param  Closure(): JsonResponse  $action
     */
    private function json(DatabaseConnection $connection, Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (PasswordRequired) {
            return response()->json(['reason' => 'password'], 409);
        } catch (GridQueryFailed $failed) {
            return response()->json(['reason' => 'query', 'error' => $failed->toArray()], 422);
        } catch (ConnectionNotAllowed $exception) {
            return response()->json(['reason' => 'connection', 'problem' => $exception->problem->toArray()], 503);
        } catch (QueryException|PDOException $exception) {
            if (! $this->isTargetError($exception)) {
                throw $exception;
            }

            return response()->json(['reason' => 'connection', 'problem' => $connection->driver->dialect()->describeConnectionError($exception)->toArray()], 503);
        }
    }
}
