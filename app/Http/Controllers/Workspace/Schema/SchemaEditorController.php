<?php

namespace App\Http\Controllers\Workspace\Schema;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Connections\TargetConnections;
use App\Connections\WriteUnlock;
use App\Enums\ApplyMode;
use App\Enums\ConnectionEnvironment;
use App\Enums\DatabaseDriver;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Requests\Workspace\Schema\SchemaChangeRequest;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Schema\ColumnTypes;
use App\Services\Schema\ForeignKeySpec;
use App\Services\Schema\SchemaChangeFailed;
use App\Services\Schema\SchemaChanges;
use App\Services\Schema\SchemaPlan;
use App\Services\Schema\SchemaPlans;
use App\Services\Server\ServerInspector;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PDOException;

/**
 * Strukturänderungen im Arbeitsbildschirm (S-02 bis S-05): zuerst die Vorschau (JSON) — genau die Anweisungen, die
 * laufen werden, mit dem Verfahren je Treiber (ADR-003) —, angewendet erst nach Bestätigung (Weiterleitung mit
 * Meldung). Nur mit dem Recht «schreiben» und nie auf einer schreibgeschützten Verbindung.
 */
abstract class SchemaEditorController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        protected readonly SchemaPlans $plans,
        protected readonly SchemaChanges $changes,
        protected readonly ServerInspector $inspector,
        protected readonly TargetConnections $targets,
    ) {
        parent::__construct($catalog);
    }

    /**
     * Seiten zum Bearbeiten nur für Konten, die schreiben dürfen, und nie bei Schreibschutz (403) — ausser er ist auf
     * Zeit aufgehoben (V-11), wie überall sonst.
     */
    protected function authorizeEditing(DatabaseConnection $connection): void
    {
        abort_if($connection->read_only && ! app(WriteUnlock::class)->isActive($connection), 403, __('Diese Verbindung ist schreibgeschützt — Strukturänderungen sind gesperrt.'));
        Gate::authorize('write', $connection);
    }

    /**
     * Was das Formular je Treiber braucht: Typen, Regeln für Fremdschlüssel, Verfahren, Bestätigung auf Produktion.
     *
     * @return array<string, mixed>
     */
    protected function editor(DatabaseConnection $connection, string $schema, ?string $database): array
    {
        $driver = $connection->driver;
        $mode = ApplyMode::for($driver);
        $target = $this->targets->open($connection, $database);

        return [
            'types' => ColumnTypes::options($driver),
            'rules' => ForeignKeySpec::RULES,
            'tables' => $this->inspector->tablesOn($target, $connection, $schema, withViews: false),
            'mode' => [
                'key' => $mode->value,
                'title' => $mode->title($driver),
                'explanation' => $mode->explanation($driver),
            ],
            'comments' => $driver !== DatabaseDriver::Sqlite,
            'positions' => in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true),
            'confirmName' => $connection->environment === ConnectionEnvironment::Production ? $connection->name : null,
        ];
    }

    /**
     * Spalten aller Tabellen des Schemas als Ziel von Fremdschlüsseln — eine Abfrage je Treiber.
     *
     * @return array<string, list<string>>
     */
    protected function targetColumns(DatabaseConnection $connection, string $schema, ?string $database): array
    {
        return $this->catalog->completion($connection, $schema, $database);
    }

    /**
     * Vorschau als JSON: geprüft wie beim Anwenden, aber ohne etwas auszuführen.
     *
     * @param  Closure(Connection): SchemaPlan  $plan
     *
     * @throws ValidationException
     */
    protected function previewResponse(SchemaChangeRequest $request, Closure $plan): JsonResponse
    {
        $connection = $request->targetConnection();

        try {
            return response()->json($this->changes->preview($connection, $plan, $this->requestedDatabase($request))->preview());
        } catch (PasswordRequired|ConnectionNotAllowed|QueryException|PDOException $exception) {
            throw ValidationException::withMessages(['server' => $this->problemText($connection, $exception)]);
        }
    }

    /**
     * Anwenden; scheitert eine Anweisung, steht danach im Formular, was gelaufen ist (MySQL, MariaDB) bzw. dass nichts
     * angewendet wurde (PostgreSQL, SQLite).
     *
     * @param  Closure(Connection): SchemaPlan  $plan
     * @param  Closure(SchemaPlan): RedirectResponse  $done
     */
    protected function applyChange(SchemaChangeRequest $request, Closure $plan, Closure $done): RedirectResponse
    {
        $connection = $request->targetConnection();

        try {
            $applied = $this->changes->apply($connection, $this->user($request), $plan, $this->requestedDatabase($request), $request->confirmedName());
        } catch (SchemaChangeFailed $failure) {
            Inertia::flash('schemaFailure', $failure->toArray());

            return back()->withErrors(['server' => $failure->getMessage()]);
        } catch (PasswordRequired|ConnectionNotAllowed|QueryException|PDOException $exception) {
            return back()->withErrors(['server' => $this->problemText($connection, $exception)]);
        }

        return $done($applied);
    }

    /**
     * Gewählte Datenbank bei PostgreSQL mit «alle Datenbanken» — nur aus der Liste des Servers.
     *
     * @throws ValidationException
     */
    protected function requestedDatabase(SchemaChangeRequest $request): ?string
    {
        return $this->inspector->database($request->targetConnection(), $request->database());
    }

    /**
     * Adresse mit gewählter Datenbank (`?db=`), wenn es eine gibt.
     *
     * @param  array<int|string, mixed>  $parameters
     * @return array<int|string, mixed>
     */
    protected function withDatabase(array $parameters, ?string $database): array
    {
        return $database === null ? $parameters : [...$parameters, 'db' => $database];
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    private function problemText(DatabaseConnection $connection, PasswordRequired|ConnectionNotAllowed|QueryException|PDOException $exception): string
    {
        return match (true) {
            $exception instanceof PasswordRequired => __('Für diese Verbindung fehlt in dieser Sitzung das Passwort.'),
            $exception instanceof ConnectionNotAllowed => $exception->problem->title(),
            default => $connection->driver->dialect()->describeConnectionError($exception)->title(),
        };
    }
}
