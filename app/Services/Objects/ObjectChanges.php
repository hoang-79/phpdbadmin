<?php

namespace App\Services\Objects;

use App\Connections\TargetConnections;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Führt Änderungen an Objekten aus (S-06). Die Vorschau plant über die Leseverbindung und führt nichts aus; das
 * Anwenden plant dasselbe noch einmal über eine Verbindung ohne Schreibschutz (nie für eine schreibgeschützte, die
 * Freigabe auf Zeit V-11 zählt) und läuft je nach Treiber in einer Transaktion oder Anweisung für Anweisung. Jede
 * Änderung ergibt genau einen Eintrag im Protokoll — `object.create`, `object.alter`, `object.drop` —, gelungen oder
 * gescheitert.
 *
 * Ausgeführt wird ohne Vorbereiten (PDO::exec): `CREATE PROCEDURE` geht in MySQL nicht als vorbereitete Anweisung,
 * und ein «?» im Rumpf einer PostgreSQL-Funktion ist kein Platzhalter.
 */
final class ObjectChanges
{
    public function __construct(private readonly TargetConnections $targets, private readonly AuditLog $audit) {}

    /**
     * @param  Closure(Connection): ObjectPlan  $plan
     */
    public function preview(DatabaseConnection $connection, Closure $plan, ?string $database = null): ObjectPlan
    {
        return $plan($this->targets->open($connection, $database));
    }

    /**
     * Scheitert eine Anweisung, endet der Lauf mit ObjectChangeFailed (Eintrag im Protokoll schon geschrieben).
     *
     * @param  Closure(Connection): ObjectPlan  $plan
     */
    public function apply(DatabaseConnection $connection, User $user, Closure $plan, ?string $database = null): ObjectPlan
    {
        return $this->targets->withWritable($connection, function (Connection $writable) use ($connection, $user, $plan): ObjectPlan {
            $planned = $plan($writable);

            $planned->transactional
                ? $this->inTransaction($connection, $user, $writable, $planned)
                : $this->oneByOne($connection, $user, $writable, $planned);

            $this->record($connection, $user, $planned, count($planned->statements));

            return $planned;
        }, $database);
    }

    private function inTransaction(DatabaseConnection $connection, User $user, Connection $writable, ObjectPlan $plan): void
    {
        $writable->beginTransaction();

        try {
            foreach ($plan->statements as $statement) {
                $this->execute($writable, $statement);
            }

            $writable->commit();
        } catch (Throwable $error) {
            $this->rollBackQuietly($writable);
            $message = $this->serverMessage($error);
            $this->record($connection, $user, $plan, 0, $message);

            throw new ObjectChangeFailed($message, $plan);
        }
    }

    /**
     * MySQL und MariaDB: ohne Transaktion. Scheitert das Neuanlegen nach dem DROP, wird die bisherige Definition
     * wieder angelegt.
     */
    private function oneByOne(DatabaseConnection $connection, User $user, Connection $writable, ObjectPlan $plan): void
    {
        foreach ($plan->statements as $index => $statement) {
            try {
                $this->execute($writable, $statement);
            } catch (Throwable $error) {
                $message = $this->serverMessage($error);
                $restored = null;

                if ($plan->restoresAfter($index)) {
                    try {
                        $this->execute($writable, (string) $plan->restore);
                        $restored = true;
                    } catch (Throwable) {
                        $restored = false;
                    }
                }

                $this->record($connection, $user, $plan, $index, $message, $restored);

                throw new ObjectChangeFailed($message, $plan, $restored);
            }
        }
    }

    /**
     * Eine Anweisung aus dem Plan: die Definition, die die Person selbst geschrieben und in der Vorschau bestätigt hat,
     * oder ein DROP mit gequoteten Namen aus dem Katalog — genau eine Anweisung (geprüft beim Planen).
     */
    private function execute(Connection $connection, string $sql): void
    {
        $connection->getPdo()->exec($sql);
    }

    private function record(DatabaseConnection $connection, User $user, ObjectPlan $plan, int $executed, ?string $error = null, ?bool $restored = null): void
    {
        $this->audit->record($plan->action, $user, $plan->target(), array_filter([
            'connection' => $connection->id,
            'connection_name' => $connection->name,
            'driver' => $connection->driver->value,
            'kind' => $plan->kind->value,
            'qualifier' => $plan->qualifier,
            'outcome' => $error === null ? 'ok' : 'failed',
            'statements' => count($plan->statements),
            'executed' => $executed,
            'transaction' => $plan->transactional,
            'restored' => $restored,
        ], fn (mixed $value): bool => $value !== null), connection: $connection, sql: implode(";\n", $plan->preview()['statements']), error: $error);
    }

    /** Meldung des Servers ohne die Anweisung, die Laravel an die QueryException hängt. */
    private function serverMessage(Throwable $error): string
    {
        return ($error instanceof QueryException ? ($error->getPrevious() ?? $error) : $error)->getMessage();
    }

    private function rollBackQuietly(Connection $connection): void
    {
        try {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // Die Transaktion ist schon zu.
        }
    }
}
