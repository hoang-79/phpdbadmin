<?php

namespace App\Services\Server;

use App\Connections\TargetConnections;
use App\Dialects\PlannedStatement;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Führt ändernde Aktionen der Server-Verwaltung aus (W-02, W-04, W-05). Die Vorschau plant über die Leseverbindung und
 * führt nichts aus; das Ausführen plant dasselbe noch einmal über eine Verbindung ohne Schreibschutz und läuft
 * Anweisung für Anweisung — bei einem Fehler wird gestoppt. Jede ausgeführte Aktion ergibt genau einen Eintrag im
 * Protokoll, gelungen oder gescheitert, immer ohne Passwort.
 */
final class ServerChanges
{
    public function __construct(private readonly TargetConnections $targets, private readonly AuditLog $audit) {}

    /**
     * @param  Closure(Connection): ServerPlan  $plan
     */
    public function preview(DatabaseConnection $connection, Closure $plan, ?string $database = null): ServerPlan
    {
        return $plan($this->targets->open($connection, $database));
    }

    /**
     * Scheitert eine Anweisung, endet der Lauf mit ServerChangeFailed (Meldung maskiert, Eintrag im Protokoll).
     *
     * @template T
     *
     * @param  Closure(Connection): ServerPlan  $plan
     * @param  Closure(Connection, PlannedStatement): T  $run
     * @return array{plan: ServerPlan, results: list<T>}
     */
    public function apply(DatabaseConnection $connection, User $user, Closure $plan, Closure $run, ?string $database = null, ?int $statementTimeoutMs = null): array
    {
        return $this->targets->withWritable($connection, function (Connection $writable) use ($connection, $user, $plan, $run): array {
            $planned = $plan($writable);
            // Anweisungen mit Passwort nie an Abfrage-Ereignisse weitergeben (Protokolle, Entwicklerwerkzeuge).
            $writable->unsetEventDispatcher();
            $results = [];

            try {
                foreach ($planned->statements as $statement) {
                    $results[] = $run($writable, $statement);
                }
            } catch (Throwable $error) {
                $message = $planned->mask($this->serverMessage($error));
                $this->record($connection, $user, $planned, count($results), $message);

                throw new ServerChangeFailed($message, count($results), $planned);
            }

            $this->record($connection, $user, $planned, count($results));

            return ['plan' => $planned, 'results' => $results];
        }, $database, $statementTimeoutMs);
    }

    private function record(DatabaseConnection $connection, User $user, ServerPlan $plan, int $executed, ?string $error = null): void
    {
        $this->audit->record($plan->action, $user, $plan->target, [
            'connection' => $connection->id,
            'connection_name' => $connection->name,
            'driver' => $connection->driver->value,
            ...$plan->context,
            'outcome' => $error === null ? 'ok' : 'failed',
            'statements' => count($plan->statements),
            'executed' => $executed,
        ], connection: $connection, sql: $plan->auditSql(), error: $error);
    }

    /**
     * Meldung des Servers ohne die Anweisung: Laravel hängt sie samt Werten an die QueryException an (F-7).
     */
    private function serverMessage(Throwable $error): string
    {
        return ($error instanceof QueryException ? ($error->getPrevious() ?? $error) : $error)->getMessage();
    }
}
