<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Models\DatabaseConnection;
use App\Services\Ai\AssistantFailed;
use App\Services\Ai\SqlAssistant;
use App\Services\Audit\AuditLog;
use App\Services\Audit\Masking;
use App\Services\Catalog\Catalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use PDOException;

/**
 * SQL-Hilfe mit KI im Editor (Q-08): Frage und Schema hin, eine Anweisung zum Prüfen zurück. Nur wenn die
 * Installation sie eingeschaltet hat (sonst 404), nur mit Recht «ansehen» und dem eigenen Schlüssel des Kontos.
 * Jede Anfrage steht im Protokoll — ohne Frage, ohne Antwort, nur dass ein Schema das Haus verlassen hat.
 */
class SqlAssistController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        private readonly SqlAssistant $assistant,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($catalog);
    }

    public function __invoke(Request $request, DatabaseConnection $connection): JsonResponse
    {
        abort_unless(SqlAssistant::enabled(), 404);
        Gate::authorize('view', $connection);

        try {
            $database = $this->database($request, $connection);
            $validated = $request->validate([
                'question' => ['required', 'string', 'min:3', 'max:2000'],
                'schema' => ['required', 'string', Rule::in($this->catalog->schemas($connection, $database))],
            ], [], ['question' => __('Frage'), 'schema' => __('Schema')]);

            $answer = $this->assistant->suggest($this->user($request), $connection, $validated['schema'], $database, $validated['question']);
        } catch (AssistantFailed $failed) {
            return response()->json(['reason' => 'assistant', 'message' => $failed->getMessage()], 422);
        } catch (PasswordRequired) {
            return response()->json(['reason' => 'password'], 409);
        } catch (ConnectionNotAllowed $exception) {
            return response()->json(['reason' => 'connection', 'problem' => $exception->problem->toArray()], 503);
        } catch (QueryException|PDOException $exception) {
            if (! $this->isTargetError($exception)) {
                throw $exception;
            }

            return response()->json(['reason' => 'database', 'message' => Masking::exception($exception)], 422);
        }

        $this->audit->record('sql.assist', $this->user($request), $validated['schema'], [
            'model' => (string) config('phpdbadmin.ai.model'),
            'tables' => $answer['tables'],
            'omitted' => $answer['omitted'],
        ], connection: $connection);

        return response()->json($answer);
    }
}
