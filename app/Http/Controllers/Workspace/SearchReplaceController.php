<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\ConnectionProblemKind;
use App\Connections\PasswordRequired;
use App\Connections\ReadOnlyConnection;
use App\Enums\ConnectionEnvironment;
use App\Http\Requests\Workspace\ReplaceRequest;
use App\Models\DatabaseConnection;
use App\Services\Audit\Masking;
use App\Services\Catalog\Catalog;
use App\Services\Data\ChangeConflict;
use App\Services\Data\ChangeRejected;
use App\Services\Data\SearchReplace;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use PDOException;

/**
 * Suchen und Ersetzen in einer Tabelle (D-08): Vorschau für alle mit Zugang, Anwenden nur mit Recht «schreiben»,
 * ohne Schreibschutz (oder mit Freigabe auf Zeit, V-11) und in Produktion mit dem Namen der Verbindung. Über der
 * Grenze für Rückgängig nur mit ausdrücklicher Bestätigung.
 */
class SearchReplaceController extends WorkspaceController
{
    public function __construct(Catalog $catalog, private readonly SearchReplace $replacer)
    {
        parent::__construct($catalog);
    }

    public function preview(ReplaceRequest $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        return $this->answer($connection, fn (): JsonResponse => response()->json(
            $this->replacer->preview($connection, $this->database($request, $connection), $schema, $object, $request->replacement()),
        ));
    }

    public function apply(ReplaceRequest $request, DatabaseConnection $connection, string $schema, string $object): JsonResponse
    {
        Gate::authorize('write', $connection);

        if ($connection->environment === ConnectionEnvironment::Production && $request->validated('confirmation') !== $connection->name) {
            return response()->json(['reason' => 'confirm', 'message' => __('Geben Sie zur Bestätigung den Namen der Verbindung ein.')], 409);
        }

        return $this->answer($connection, fn (): JsonResponse => response()->json(
            $this->replacer->apply($connection, $this->user($request), $this->database($request, $connection), $schema, $object, $request->replacement(), $request->boolean('without_undo')),
        ));
    }

    /**
     * Fehler mit Ursache wie beim Bearbeiten: abgewiesen (422), Konflikt (409), Datenbank lehnt ab (422, ohne Werte),
     * schreibgeschützt (403), Passwort fehlt (409), Verbindung weg (503).
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
                ? response()->json(['reason' => 'database', 'message' => __('Die Datenbank hat das Ersetzen abgelehnt — nichts wurde geändert: :message', ['message' => Masking::exception($exception)])], 422)
                : response()->json(['reason' => 'connection', 'problem' => $problem->toArray()], 503);
        }
    }

    private function readOnlyText(): string
    {
        return __('Diese Verbindung ist schreibgeschützt. Heben Sie den Schutz auf Zeit auf, um zu ändern.');
    }
}
