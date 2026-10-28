<?php

namespace App\Http\Controllers\Workspace\Server;

use App\Http\Requests\Workspace\Server\EndProcessRequest;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerPlan;
use Illuminate\Database\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Prozesse und Sitzungen (W-02): ansehen und beenden — nur die laufende Anweisung oder die ganze Sitzung. Die eigene
 * Sitzung von phpdbadmin ist markiert und lässt sich nicht beenden; SQLite hat keine Serverprozesse.
 */
class ProcessController extends ServerController
{
    public function index(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->page($request, $connection, 'processes', function (?string $database) use ($connection): array {
            $dialect = $connection->driver->dialect();
            $processes = $this->inspector->processes($connection, $database);

            return [
                // Je Prozess genau die Anweisungen, die «Abbrechen» und «Beenden» ausführen — für die Bestätigung.
                'processes' => $processes === null ? null : array_map(fn (array $process): array => [
                    ...$process,
                    'endStatements' => [
                        'query' => (string) $dialect->endProcessStatement($process['id'], false)?->preview(),
                        'session' => (string) $dialect->endProcessStatement($process['id'], true)?->preview(),
                    ],
                ], $processes),
            ];
        });
    }

    public function end(EndProcessRequest $request, DatabaseConnection $connection, string $process): RedirectResponse
    {
        $id = (int) $process;
        $wholeSession = $request->wholeSession();
        $user = $this->user($request);
        $dialect = $connection->driver->dialect();

        return $this->attempt($connection, function () use ($connection, $user, $dialect, $id, $wholeSession): RedirectResponse {
            $this->changes->apply(
                $connection,
                $user,
                fn (Connection $target): ServerPlan => $this->plans->endProcess($target, $connection, $id, $wholeSession),
                fn (Connection $target): bool => $dialect->endProcess($target, $id, $wholeSession)
                    ?: throw new RuntimeException($this->goneMessage($id)),
            );

            Inertia::flash('toast', ['type' => 'success', 'message' => $this->doneMessage($id, $wholeSession)]);

            return back();
        });
    }

    private function goneMessage(int $id): string
    {
        return __('Den Prozess :id gibt es nicht mehr.', ['id' => $id]);
    }

    private function doneMessage(int $id, bool $wholeSession): string
    {
        return $wholeSession
            ? __('Sitzung :id beendet.', ['id' => $id])
            : __('Anweisung in Sitzung :id abgebrochen.', ['id' => $id]);
    }
}
