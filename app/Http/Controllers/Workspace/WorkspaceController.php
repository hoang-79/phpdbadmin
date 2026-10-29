<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Connections\WriteUnlock;
use App\Enums\DatabaseDriver;
use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Catalog\Catalog;
use App\Support\Translations;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;

/**
 * Arbeitsbildschirm (Wireframe 05): Kopf mit Umgebung, Navigator links (O-01), Inhalt rechts. Eine Verbindung, die
 * kein Passwort speichert, fragt zuerst danach; eine, die scheitert, zeigt den Fehler in Klartext statt einer
 * Fehlerseite.
 *
 * @phpstan-import-type NavigatorGroup from Catalog
 */
class WorkspaceController extends Controller
{
    public function __construct(protected readonly Catalog $catalog) {}

    public function show(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        // «zuletzt geöffnet» in der Übersicht (V-06)
        $connection->markOpenedBy($this->user($request));

        return $this->guarded($request, $connection, function () use ($request, $connection): Response|RedirectResponse {
            $database = $this->database($request, $connection);
            $schema = $this->catalog->defaultSchema($connection, $database);

            return $schema === null
                ? $this->render($request, $connection, 'workspace/Schema', [], null, [], $database)
                : to_route('workspace.schema', array_filter([$connection, $schema, 'db' => $database], fn ($value) => $value !== null));
        });
    }

    public function schema(Request $request, DatabaseConnection $connection, string $schema): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection, $schema): Response {
            $database = $this->database($request, $connection);
            $schemas = $this->catalog->schemas($connection, $database);
            abort_unless(in_array($schema, $schemas, true), 404);

            return $this->render($request, $connection, 'workspace/Schema', $schemas, $schema, $this->catalog->groups($connection, $schema, $database), $database);
        });
    }

    /**
     * Gewählte Datenbank bei PostgreSQL mit «alle Datenbanken» (`?db=`), sonst null. Unbekannte Namen sind 404.
     */
    protected function database(Request $request, DatabaseConnection $connection): ?string
    {
        $databases = $this->catalog->databases($connection);

        if ($databases === null) {
            return null;
        }

        $requested = $request->query('db');

        if (is_string($requested) && $requested !== '') {
            abort_unless(in_array($requested, $databases, true), 404);

            return $requested;
        }

        return in_array(Catalog::POSTGRES_MAINTENANCE_DATABASE, $databases, true)
            ? Catalog::POSTGRES_MAINTENANCE_DATABASE
            : ($databases[0] ?? null);
    }

    /**
     * Verbindungsfehler und fehlendes Passwort abfangen — alles andere bleibt ein Fehler der App.
     *
     * @param  Closure(): (Response|RedirectResponse)  $action
     */
    protected function guarded(Request $request, DatabaseConnection $connection, Closure $action): Response|RedirectResponse
    {
        try {
            return $action();
        } catch (PasswordRequired) {
            redirect()->setIntendedUrl($request->fullUrl());

            return to_route('workspace.password', $connection);
        } catch (ConnectionNotAllowed $exception) {
            return $this->unavailable($request, $connection, $exception->problem->toArray());
        } catch (QueryException|PDOException $exception) {
            if (! $this->isTargetError($exception)) {
                throw $exception;
            }

            return $this->unavailable($request, $connection, $connection->driver->dialect()->describeConnectionError($exception)->toArray());
        }
    }

    /**
     * Nur Fehler der Zielverbindung werden zur Fehlerkarte. Ein Fehler der eigenen App-Datenbank ist ein Fehler der
     * App und darf nicht als «Verbindung gescheitert» erscheinen.
     */
    protected function isTargetError(QueryException|PDOException $exception): bool
    {
        return ! $exception instanceof QueryException || str_starts_with((string) $exception->connectionName, 'target_');
    }

    /**
     * @param  list<string>  $schemas
     * @param  list<NavigatorGroup>  $groups
     * @param  array<string, mixed>  $props
     */
    protected function render(Request $request, DatabaseConnection $connection, string $component, array $schemas, ?string $schema, array $groups, ?string $database = null, array $props = []): Response
    {
        $user = $this->user($request);
        $databases = $this->catalog->databases($connection);

        return Inertia::render($component, [
            'workspace' => [
                'connection' => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'driver' => $connection->driver->value,
                    'driverLabel' => $connection->driver->label(),
                    'serverVersion' => $connection->server_version,
                    'environment' => $connection->environment->value,
                    'readOnly' => $connection->read_only,
                    'accountWarnings' => Translations::accountWarnings($connection->account_warnings ?? []),
                    'canManage' => $connection->isManageableBy($user),
                    // Recht «schreiben» (V-11, S-02 bis S-05); ob gerade geschrieben werden darf, sagt zusätzlich
                    // readOnly und unlockedUntil.
                    'canWrite' => Gate::forUser($user)->allows('write', $connection),
                    // Schreibschutz auf Zeit aufgehoben (V-11): sichtbar im Kopf, bis wann.
                    'unlockedUntil' => $connection->read_only ? app(WriteUnlock::class)->until($connection)?->toIso8601String() : null,
                ],
                // PostgreSQL mit «alle Datenbanken»: Auswahl im Navigator, die gewählte in der Adresse (?db=).
                'databases' => $databases,
                'database' => $database,
                // Verbindung auf eine Datenbank festgelegt: Datenbanken (MySQL, MariaDB) lassen sich dann nicht löschen.
                'databaseFixed' => $connection->database !== null,
                'databaseLabel' => match ($connection->driver) {
                    DatabaseDriver::Postgres => $database ?? $connection->database ?? Catalog::POSTGRES_MAINTENANCE_DATABASE,
                    DatabaseDriver::Sqlite => basename((string) $connection->sqlite_path),
                    default => null,
                },
                'schemaKind' => $connection->driver === DatabaseDriver::Postgres ? 'schema' : 'database',
                'schemas' => $schemas,
                'schema' => $schema,
                'groups' => $groups,
            ],
            ...$props,
        ]);
    }

    /**
     * @param  array<string, mixed>  $problem
     */
    private function unavailable(Request $request, DatabaseConnection $connection, array $problem): Response
    {
        return Inertia::render('connect/Unavailable', [
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'canManage' => $connection->isManageableBy($this->user($request)),
            ],
            'problem' => $problem,
        ]);
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
