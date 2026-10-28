<?php

namespace App\Http\Controllers\Workspace\Server;

use App\Dialects\PlannedStatement;
use App\Enums\MaintenanceOperation;
use App\Http\Requests\Workspace\Server\MaintenanceRequest;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerPlan;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Wartung (W-05): ANALYZE, VACUUM (PostgreSQL, SQLite), OPTIMIZE (MySQL, MariaDB), Integritätsprüfung (SQLite,
 * MySQL/MariaDB) je Tabelle oder Schema — Vorschau, Bestätigung, Ergebnis je Tabelle.
 */
class MaintenanceController extends ServerController
{
    public function index(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->page($request, $connection, 'maintenance', function (?string $database) use ($request, $connection): array {
            $dialect = $connection->driver->dialect();

            return [
                'operations' => array_map(fn (MaintenanceOperation $operation): array => [
                    'key' => $operation->value,
                    'forTables' => $dialect->maintenanceForTables($operation),
                ], $dialect->maintenanceOperations()),
                // Tabellen erst, wenn ein Schema gewählt ist (?tables_in=).
                'tables' => Inertia::optional(fn (): array => $this->inspector->tables($connection, $database, (string) $request->query('tables_in', ''), withViews: false)),
            ];
        });
    }

    public function preview(MaintenanceRequest $request, DatabaseConnection $connection): JsonResponse
    {
        return $this->previewResponse($connection, fn (): ServerPlan => $this->changes->preview(
            $connection,
            fn (Connection $target): ServerPlan => $this->plans->maintenance($target, $connection, $request->operation(), (string) $request->validated('schema'), $request->table()),
            $this->inspector->database($connection, $this->requestedDatabase($request)),
        ));
    }

    public function run(MaintenanceRequest $request, DatabaseConnection $connection): RedirectResponse
    {
        $user = $this->user($request);
        $dialect = $connection->driver->dialect();

        return $this->attempt($connection, function () use ($request, $connection, $user, $dialect): RedirectResponse {
            $outcome = $this->changes->apply(
                $connection,
                $user,
                fn (Connection $target): ServerPlan => $this->plans->maintenance($target, $connection, $request->operation(), (string) $request->validated('schema'), $request->table()),
                fn (Connection $target, PlannedStatement $statement): array => $dialect->runMaintenance($target, $statement),
                $this->inspector->database($connection, $this->requestedDatabase($request)),
                (int) config('phpdbadmin.connections.maintenance_timeout_ms', 600000),
            );
            $results = array_merge(...$outcome['results']);
            $problems = count(array_filter($results, fn (array $result): bool => $result['status'] === 'error'));

            Inertia::flash('maintenance', [
                'operation' => $request->operation()->value,
                'target' => $outcome['plan']->target,
                'results' => $results,
            ]);
            Inertia::flash('toast', $problems === 0
                ? ['type' => 'success', 'message' => $this->doneMessage($outcome['plan']->target)]
                : ['type' => 'warning', 'message' => $this->problemMessage($problems)]);

            return back();
        });
    }

    private function requestedDatabase(MaintenanceRequest $request): ?string
    {
        $database = $request->validated('database');

        return is_string($database) && $database !== '' ? $database : null;
    }

    private function doneMessage(string $target): string
    {
        return __('Wartung für :target abgeschlossen.', ['target' => $target]);
    }

    private function problemMessage(int $problems): string
    {
        return trans_choice('Wartung abgeschlossen, :count Befund.|Wartung abgeschlossen, :count Befunde.', $problems, ['count' => $problems]);
    }
}
