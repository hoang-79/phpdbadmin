<?php

namespace App\Services\Access;

use App\Enums\ConnectionPermission;
use App\Models\ConnectionMembership;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Teilen und Rechte vergeben (V-09, A-07) — für den Dialog «Teilen» der Übersicht und die Rechte je Konto in der
 * Verwaltung. Jede Änderung steht einmal im Protokoll; was sich nicht ändert, erzeugt keinen Eintrag.
 */
final class ConnectionAccess
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Teilen ein- oder ausschalten. Ausschalten entzieht allen Konten ausser dem Besitzer den Zugriff — die
     * Verbindung ist danach wieder persönlich und für alle anderen 404.
     */
    public function setShared(DatabaseConnection $connection, bool $shared, User $actor): bool
    {
        if ($connection->shared === $shared) {
            return false;
        }

        DB::transaction(function () use ($connection, $shared): void {
            $connection->forceFill(['shared' => $shared])->save();

            if (! $shared) {
                $connection->memberships()->where('user_id', '!=', $connection->owner_id)->delete();
            }
        });

        $connection->unsetRelation('memberships');
        $this->audit->record($shared ? 'connection.shared' : 'connection.unshared', $actor, $connection->id, ['name' => $connection->name]);

        return true;
    }

    /**
     * Recht eines Kontos an einer geteilten Verbindung setzen oder mit null entziehen. Besitzer und Administratoren
     * haben ihr Recht aus Besitz bzw. Rolle; für sie wird nichts gespeichert.
     */
    public function grant(DatabaseConnection $connection, User $account, ?ConnectionPermission $permission, User $actor): bool
    {
        if (! $connection->shared || ! $this->needsEntry($connection, $account)) {
            return false;
        }

        $current = $connection->membershipOf($account)?->permission;

        if ($current === $permission) {
            return false;
        }

        $entry = ConnectionMembership::query()->where('connection_id', $connection->id)->where('user_id', $account->id);

        if ($permission === null) {
            $entry->delete();
        } else {
            ConnectionMembership::query()->upsert(
                [['connection_id' => $connection->id, 'user_id' => $account->id, 'permission' => $permission->value]],
                ['connection_id', 'user_id'],
                ['permission'],
            );
        }

        $connection->unsetRelation('memberships');
        $this->audit->record('connection.permission', $actor, $account->email, [
            'name' => $connection->name,
            'connection' => $connection->id,
            'account' => $account->name,
            'from' => $current?->value,
            'to' => $permission?->value,
        ]);

        return true;
    }

    /** Nur Mitglieder bekommen ein gespeichertes Recht. */
    public function needsEntry(DatabaseConnection $connection, User $account): bool
    {
        return $account->id !== $connection->owner_id && ! $account->isAdmin();
    }
}
