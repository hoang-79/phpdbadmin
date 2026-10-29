<?php

namespace App\Services\Schema;

use App\Connections\TargetConnections;
use App\Enums\ApplyMode;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Pdo\Sqlite;
use RuntimeException;
use Throwable;

/**
 * Wendet Strukturänderungen an (S-02 bis S-05) — je Treiber nach ADR-003:
 *
 * - MySQL und MariaDB Anweisung für Anweisung ohne Transaktion (DDL schliesst sie dort implizit ab); bei einem Fehler
 *   wird angehalten, die Meldung sagt, wie viele schon liefen.
 * - PostgreSQL alles in einer Transaktion.
 * - SQLite nach sqlite.org: `PRAGMA foreign_keys = OFF` ausserhalb der Transaktion, Umbau darin, vor dem Abschluss
 *   `PRAGMA foreign_key_check` (nur neue Verletzungen zählen), danach wieder `ON` — auch nach einem Fehler. In einer
 *   Transaktion wirkt das Pragma nicht, und DROP TABLE der Elterntabelle löschte sonst per CASCADE die Kinder (F-5).
 *
 * Die Vorschau plant über die Leseverbindung und führt nichts aus; das Anwenden plant dasselbe noch einmal über eine
 * Verbindung ohne Schreibschutz. Jede Anwendung ergibt genau einen Eintrag im Protokoll, gelungen oder gescheitert.
 */
final class SchemaChanges
{
    public function __construct(private readonly TargetConnections $targets, private readonly AuditLog $audit) {}

    /**
     * @param  Closure(Connection): SchemaPlan  $plan
     */
    public function preview(DatabaseConnection $connection, Closure $plan, ?string $database = null): SchemaPlan
    {
        return $plan($this->targets->open($connection, $database));
    }

    /**
     * Verlangt der Plan einen Namen zur Bestätigung (Leeren, Löschen, Spalten löschen), läuft ohne den richtigen
     * Namen keine Anweisung und es entsteht kein Eintrag im Protokoll.
     *
     * @param  Closure(Connection): SchemaPlan  $plan
     *
     * @throws SchemaChangeFailed
     * @throws ValidationException
     */
    public function apply(DatabaseConnection $connection, User $user, Closure $plan, ?string $database = null, ?string $confirmedName = null): SchemaPlan
    {
        $timeout = (int) config('phpdbadmin.connections.schema_timeout_ms', 600000);

        return $this->targets->withWritable($connection, function (Connection $writable) use ($connection, $user, $plan, $confirmedName): SchemaPlan {
            $planned = $plan($writable);
            $applied = 0;

            if ($planned->confirmName !== null && $confirmedName !== $planned->confirmName) {
                throw ValidationException::withMessages(['confirm_name' => __('Geben Sie zur Bestätigung den Namen «:name» ein.', ['name' => $planned->confirmName])]);
            }

            try {
                if ($planned->file !== null) {
                    $this->createSqliteFile($planned->file);
                } else {
                    match ($planned->mode) {
                        ApplyMode::Statements => $this->run($writable, $planned->statements, $applied),
                        ApplyMode::Transaction => $writable->transaction(function () use ($writable, $planned, &$applied): void {
                            $this->run($writable, $planned->statements, $applied);
                        }),
                        ApplyMode::Rebuild => $this->rebuild($writable, $planned->statements, $applied),
                    };
                }
            } catch (Throwable $error) {
                $kept = $planned->mode->rollsBack() ? 0 : $applied;
                $message = $this->serverMessage($error);
                $this->record($connection, $user, $planned, $kept, $message);

                throw new SchemaChangeFailed($message, $kept, $planned);
            }

            $this->record($connection, $user, $planned, $applied);

            return $planned;
        }, $database, $timeout);
    }

    /**
     * @param  list<string>  $statements
     */
    private function run(Connection $connection, array $statements, int &$applied): void
    {
        foreach ($statements as $statement) {
            $connection->statement($statement);
            $applied++;
        }
    }

    /**
     * Umbauverfahren von sqlite.org/lang_altertable.html (Abschnitt 7).
     *
     * @param  list<string>  $statements
     */
    private function rebuild(Connection $connection, array $statements, int &$applied): void
    {
        $before = $this->violations($connection);
        $connection->statement('PRAGMA foreign_keys = OFF');

        try {
            $connection->beginTransaction();

            try {
                $this->run($connection, $statements, $applied);
                $new = array_diff($this->violations($connection), $before);

                if ($new !== []) {
                    throw new RuntimeException(trans_choice(
                        'Fremdschlüssel verletzt: :count Zeile verweist danach ins Leere (:tables) — nichts angewendet.|Fremdschlüssel verletzt: :count Zeilen verweisen danach ins Leere (:tables) — nichts angewendet.',
                        count($new),
                        ['tables' => implode(', ', array_unique(array_map(fn (string $violation): string => explode('#', $violation)[0], $new)))],
                    ));
                }

                $connection->commit();
            } catch (Throwable $error) {
                $connection->rollBack();

                throw $error;
            }
        } finally {
            $connection->statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Verletzte Fremdschlüssel als «tabelle#rowid#fk» — vorher und nachher verglichen, damit ein alter Fehler in einer
     * anderen Tabelle keine Änderung blockiert.
     *
     * @return list<string>
     */
    private function violations(Connection $connection): array
    {
        return array_values(array_map(function (object $row): string {
            $values = array_values((array) $row);

            return $values[0].'#'.$values[1].'#'.$values[3];
        }, $connection->select('PRAGMA foreign_key_check')));
    }

    /** S-05 bei SQLite: eine leere Datei, die SQLite als Datenbank erkennt (Kopf geschrieben). */
    private function createSqliteFile(string $path): void
    {
        $pdo = new Sqlite('sqlite:'.$path, options: [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READWRITE | Sqlite::OPEN_CREATE]);
        $pdo->exec('PRAGMA user_version = 0');
        $pdo->exec('VACUUM');
        unset($pdo);
    }

    private function record(DatabaseConnection $connection, User $user, SchemaPlan $plan, int $applied, ?string $error = null): void
    {
        $this->audit->record($plan->action, $user, $plan->target, [
            'connection' => $connection->id,
            'connection_name' => $connection->name,
            'driver' => $connection->driver->value,
            'mode' => $plan->mode->value,
            ...$plan->context,
            'outcome' => $error === null ? 'ok' : 'failed',
            'statements' => count($plan->statements),
            'applied' => $applied,
            ...($error !== null ? ['error' => $error] : []),
        ], sql: $plan->auditSql());
    }

    /**
     * Meldung des Servers ohne die Anweisung: Laravel hängt sie an die QueryException an.
     */
    private function serverMessage(Throwable $error): string
    {
        return ($error instanceof QueryException ? ($error->getPrevious() ?? $error) : $error)->getMessage();
    }
}
