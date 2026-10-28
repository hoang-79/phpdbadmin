<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Enums\DatabaseDriver;
use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Catalog\Catalog;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return $this->guarded($request, $connection, function () use ($request, $connection): Response|RedirectResponse {
            $schema = $this->catalog->defaultSchema($connection);

            return $schema === null
                ? $this->render($request, $connection, 'workspace/Schema', [], null, [])
                : to_route('workspace.schema', [$connection, $schema]);
        });
    }

    public function schema(Request $request, DatabaseConnection $connection, string $schema): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection, $schema): Response {
            $schemas = $this->catalog->schemas($connection);
            abort_unless(in_array($schema, $schemas, true), 404);

            return $this->render($request, $connection, 'workspace/Schema', $schemas, $schema, $this->catalog->groups($connection, $schema));
        });
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
            return $this->unavailable($request, $connection, $connection->driver->dialect()->describeConnectionError($exception)->toArray());
        }
    }

    /**
     * @param  list<string>  $schemas
     * @param  list<NavigatorGroup>  $groups
     * @param  array<string, mixed>  $props
     */
    protected function render(Request $request, DatabaseConnection $connection, string $component, array $schemas, ?string $schema, array $groups, array $props = []): Response
    {
        $user = $this->user($request);

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
                    'accountWarnings' => $connection->account_warnings ?? [],
                    'canManage' => $connection->isManageableBy($user),
                ],
                'databaseLabel' => match ($connection->driver) {
                    DatabaseDriver::Postgres => $connection->database ?? 'postgres',
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
