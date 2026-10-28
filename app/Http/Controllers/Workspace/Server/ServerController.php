<?php

namespace App\Http\Controllers\Workspace\Server;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Enums\ConnectionEnvironment;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use App\Services\Server\ServerChangeFailed;
use App\Services\Server\ServerChanges;
use App\Services\Server\ServerInspector;
use App\Services\Server\ServerPlan;
use App\Services\Server\ServerPlans;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use PDOException;

/**
 * Server-Verwaltung im Arbeitsbildschirm (W-01 bis W-05): Seiten mit Navigator wie die übrigen; was ändert, zeigt
 * zuerst die Vorschau (JSON) und läuft erst nach Bestätigung (Weiterleitung mit Meldung). Ansehen darf, wer die
 * Verbindung öffnen kann; ändern nur, wer sie verwalten darf und nie auf einer schreibgeschützten.
 */
abstract class ServerController extends WorkspaceController
{
    public function __construct(
        Catalog $catalog,
        protected readonly ServerInspector $inspector,
        protected readonly ServerPlans $plans,
        protected readonly ServerChanges $changes,
    ) {
        parent::__construct($catalog);
    }

    /**
     * @param  'overview'|'processes'|'variables'|'accounts'|'maintenance'  $section
     * @param  Closure(string|null): array<string, mixed>  $props  bekommt die geöffnete Datenbank (PostgreSQL mit «alle Datenbanken»)
     */
    protected function page(Request $request, DatabaseConnection $connection, string $section, Closure $props): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection, $section, $props): Response {
            $database = $this->database($request, $connection);

            return $this->render($request, $connection, 'workspace/server/'.ucfirst($section), $this->catalog->schemas($connection, $database), null, [], $database, [
                'server' => $this->meta($request, $connection, $section),
                ...$props($database),
            ]);
        });
    }

    /**
     * Was die Oberfläche über Rechte und Bestätigung wissen muss — die Sperre selbst sitzt im Form-Request.
     *
     * @return array{section: string, hasServer: bool, canChange: bool, blocked: 'read_only'|'not_manager'|null, confirmName: string|null}
     */
    protected function meta(Request $request, DatabaseConnection $connection, string $section): array
    {
        $canManage = $connection->isManageableBy($this->user($request));

        return [
            'section' => $section,
            'hasServer' => $connection->driver->isServer(),
            'canChange' => $canManage && ! $connection->read_only,
            'blocked' => match (true) {
                $connection->read_only => 'read_only',
                ! $canManage => 'not_manager',
                default => null,
            },
            'confirmName' => $connection->environment === ConnectionEnvironment::Production ? $connection->name : null,
        ];
    }

    /**
     * Vorschau als JSON (useHttp): geprüft wie beim Ausführen, aber ohne etwas auszuführen.
     *
     * @param  Closure(): ServerPlan  $plan
     *
     * @throws ValidationException
     */
    protected function previewResponse(DatabaseConnection $connection, Closure $plan): JsonResponse
    {
        try {
            return response()->json($plan()->preview());
        } catch (PasswordRequired|ConnectionNotAllowed|QueryException|PDOException $exception) {
            throw ValidationException::withMessages(['server' => $this->problemText($connection, $exception)]);
        }
    }

    /**
     * Ändernde Aktion: Fehler des Servers und der Verbindung kommen als Meldung zurück ins Formular.
     *
     * @param  Closure(): RedirectResponse  $action
     */
    protected function attempt(DatabaseConnection $connection, Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (ServerChangeFailed $failure) {
            return back()->withErrors(['server' => $failure->getMessage()]);
        } catch (PasswordRequired|ConnectionNotAllowed|QueryException|PDOException $exception) {
            return back()->withErrors(['server' => $this->problemText($connection, $exception)]);
        }
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
