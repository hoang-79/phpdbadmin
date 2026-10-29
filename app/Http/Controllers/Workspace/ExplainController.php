<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Connections\WriteUnlock;
use App\Enums\DatabaseDriver;
use App\Http\Requests\Workspace\ExplainRequest;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Sql\Explain\Explainer;
use App\Services\Sql\Explain\ExplainRejected;
use App\Services\Sql\StatementSplitter;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use PDOException;

/**
 * «Erklären» im SQL-Editor (Q-06): die Anweisung unter der Schreibmarke (oder die markierte) als Plan-Baum mit
 * Hinweisen. Ansehen reicht — ohne ANALYZE führt der Server nichts aus, mit ANALYZE nur lesend.
 */
class ExplainController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        private readonly StatementSplitter $splitter,
        private readonly Explainer $explainer,
        private readonly WriteUnlock $unlock,
    ) {
        parent::__construct($catalog);
    }

    public function explain(ExplainRequest $request, DatabaseConnection $connection): JsonResponse
    {
        /** @var array{sql: string, cursor?: int|null, selection?: bool, schema?: string|null, analyze?: bool} $input */
        $input = $request->validated();
        $driver = $connection->driver;

        $atCursor = ($input['selection'] ?? false) ? null : $this->splitter->statementAt($input['sql'], (int) ($input['cursor'] ?? strlen($input['sql'])), $driver);
        $statements = ($input['selection'] ?? false)
            ? $this->splitter->split($input['sql'], $driver)
            : ($atCursor === null ? [] : [$atCursor]);

        if (count($statements) !== 1) {
            return response()->json(['reason' => 'explain', 'message' => $statements === [] ? $this->emptyText() : $this->manyText(count($statements))], 422);
        }

        $schema = $input['schema'] ?? null;
        $database = match (true) {
            $driver === DatabaseDriver::Postgres => $this->database($request, $connection),
            $connection->database === null && $driver !== DatabaseDriver::Sqlite => $schema,
            default => null,
        };
        $mayWrite = Gate::forUser($this->user($request))->allows('write', $connection)
            && (! $connection->read_only || $this->unlock->isActive($connection));

        try {
            return response()->json($this->explainer->explain(
                $connection,
                $database,
                $statements[0]['sql'],
                (bool) ($input['analyze'] ?? false),
                $mayWrite,
                $driver === DatabaseDriver::Postgres ? $schema : null,
            ));
        } catch (ExplainRejected $rejected) {
            return response()->json(['reason' => 'explain', 'message' => $rejected->getMessage(), 'fromServer' => $rejected->fromServer], 422);
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
    }

    private function emptyText(): string
    {
        return __('Da steht keine Anweisung — nur Kommentare oder nichts.');
    }

    private function manyText(int $count): string
    {
        return __('Bitte genau eine Anweisung erklären — markiert sind :count.', ['count' => $count]);
    }
}
