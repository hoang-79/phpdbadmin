<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\ConnectionProblemKind;
use App\Connections\PasswordRequired;
use App\Connections\ReadOnlyConnection;
use App\Enums\ConnectionEnvironment;
use App\Http\Requests\Workspace\RowChangesRequest;
use App\Models\DatabaseConnection;
use App\Services\Audit\Masking;
use App\Services\Catalog\Catalog;
use App\Services\Data\ChangeConflict;
use App\Services\Data\ChangeRejected;
use App\Services\Data\GridQuery;
use App\Services\Data\GridQueryFailed;
use App\Services\Data\RowCopy;
use App\Services\Data\RowDetail;
use App\Services\Data\RowEditor;
use App\Services\Data\TableData;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PDOException;

/**
 * Zeilen bearbeiten (D-04, D-09, E-05, Wireframes 5–7): Vorschau, Anwenden in einer Transaktion, Rückgängig, Kopieren.
 * Schreiben nur mit Recht «schreiben» und ohne Schreibschutz (oder mit Freigabe auf Zeit, V-11); in Produktion mit dem
 * Namen der Verbindung.
 */
class RowChangeController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        private readonly RowEditor $editor,
        private readonly TableData $data,
        private readonly RowDetail $rows,
        private readonly RowCopy $copier,
    ) {
        parent::__construct($catalog);
    }

    public function preview(RowChangesRequest $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        // Auch die Vorschau nur mit Recht «schreiben»: sie zählt Kaskaden über Abfragen an der Zieldatenbank.
        Gate::authorize('write', $connection);

        return $this->answer($connection, function () use ($request, $connection, $schema, $object): JsonResponse {
            return response()->json($this->editor->preview($connection, $this->database($request, $connection), $schema, $object, $request->changes()));
        });
    }

    public function apply(RowChangesRequest $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        Gate::authorize('write', $connection);

        if ($connection->environment === ConnectionEnvironment::Production && $request->validated('confirmation') !== $connection->name) {
            return response()->json(['reason' => 'confirm', 'message' => __('Geben Sie zur Bestätigung den Namen der Verbindung ein.')], 409);
        }

        return $this->answer($connection, fn (): JsonResponse => response()->json(
            $this->editor->apply($connection, $this->user($request), $this->database($request, $connection), $schema, $object, $request->changes()),
        ));
    }

    public function undo(Request $request, DatabaseConnection $connection, string $schema, string $object, int $audit): JsonResponse
    {
        Gate::authorize('write', $connection);

        return $this->answer($connection, fn (): JsonResponse => response()->json($this->editor->undo($connection, $this->user($request), $audit)));
    }

    /**
     * Kopieren als TSV, Markdown oder INSERT (E-05): eine Zeile (`key[]`) oder der aktuelle Ausschnitt, höchstens
     * 1'000 Zeilen.
     */
    public function copy(Request $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        $format = $request->validate(['format' => ['required', 'in:tsv,markdown,insert']])['format'];

        return $this->answer($connection, function () use ($request, $connection, $schema, $object, $format): JsonResponse {
            $database = $this->database($request, $connection);
            $columns = $this->data->columns($connection, $schema, $object, $database);
            abort_if($columns === null, 404);
            $key = $request->query('key');

            if (is_array($key) && $key !== []) {
                $row = $this->rows->describe($connection, $schema, $object, array_values(array_map(fn (mixed $part): string => (string) $part, $key)), $database);
                abort_if($row === null, 404);
                $values = [$row['values']];
            } else {
                $query = GridQuery::fromRequest($request);
                $page = $this->data->page($connection, $schema, $object, new GridQuery($query->sort, $query->filters, $query->where, $query->search, limit: 1000), $database);
                $values = $page['rows'] ?? [];
            }

            return response()->json([
                'text' => $this->copier->format(array_column($columns, 'name'), $values, $format, $connection->driver, $object),
                'rows' => count($values),
            ]);
        });
    }

    /**
     * Fehler mit Ursache für die Oberfläche: abgewiesen (422), Konflikt (409), Datenbank lehnt ab (422, Meldung ohne
     * Werte), schreibgeschützt (403), Passwort fehlt (409), Verbindung weg (503).
     *
     * @param  Closure(): JsonResponse  $action
     */
    private function answer(DatabaseConnection $connection, Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ChangeRejected $exception) {
            return response()->json(['reason' => 'rejected', 'message' => $exception->getMessage()], 422);
        } catch (ChangeConflict $exception) {
            return response()->json(['reason' => 'conflict', 'message' => $exception->getMessage()], 409);
        } catch (GridQueryFailed $failed) {
            return response()->json(['reason' => 'query', 'error' => $failed->toArray()], 422);
        } catch (ReadOnlyConnection) {
            return response()->json(['reason' => 'readonly', 'message' => $this->readOnlyText()], 403);
        } catch (PasswordRequired) {
            return response()->json(['reason' => 'password'], 409);
        } catch (ConnectionNotAllowed $exception) {
            return response()->json(['reason' => 'connection', 'problem' => $exception->problem->toArray()], 503);
        } catch (QueryException|PDOException $exception) {
            if (! $this->isTargetError($exception)) {
                throw $exception;
            }

            $problem = $connection->driver->dialect()->describeConnectionError($exception);

            return $problem->kind === ConnectionProblemKind::Unknown
                ? response()->json(['reason' => 'database', 'message' => Masking::exception($exception)], 422)
                : response()->json(['reason' => 'connection', 'problem' => $problem->toArray()], 503);
        }
    }

    private function readOnlyText(): string
    {
        return __('Diese Verbindung ist schreibgeschützt. Heben Sie den Schutz auf Zeit auf, um zu ändern.');
    }
}
