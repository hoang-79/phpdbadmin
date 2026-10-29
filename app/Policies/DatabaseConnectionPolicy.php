<?php

namespace App\Policies;

use App\Enums\ConnectionPermission;
use App\Models\DatabaseConnection;
use App\Models\User;

/**
 * Rechte je Verbindung (A-07, V-09). Die Rechte bauen aufeinander auf — lesen ⊂ schreiben ⊂ verwalten:
 *
 * - **Administratoren** dürfen an jeder geteilten Verbindung alles;
 * - der **Besitzer** einer Verbindung darf an ihr alles;
 * - **Mitglieder** einer geteilten Verbindung dürfen, was ihr Recht in `connection_user` zulässt.
 *
 * Persönliche Verbindungen anderer sieht niemand. Ob eine Verbindung überhaupt sichtbar ist, entscheidet schon die
 * Routenbindung (`{connection}` → 404, T-16); die Policy antwortet für sichtbare Verbindungen mit 403.
 */
class DatabaseConnectionPolicy
{
    /** Ansehen: Daten und Struktur lesen, SQL nur lesend, exportieren. */
    public function view(User $user, DatabaseConnection $connection): bool
    {
        return $connection->permissionFor($user) !== null;
    }

    /** Schreiben: Daten und Struktur ändern, importieren, Schreibschutz auf Zeit aufheben (V-11). */
    public function write(User $user, DatabaseConnection $connection): bool
    {
        return $connection->permissionFor($user)?->allows(ConnectionPermission::Write) ?? false;
    }

    /** Verwalten: Verbindung bearbeiten, testen mit dem gespeicherten Passwort, entfernen. */
    public function manage(User $user, DatabaseConnection $connection): bool
    {
        return $connection->permissionFor($user)?->allows(ConnectionPermission::Manage) ?? false;
    }

    /** Teilen und Rechte vergeben (V-09): nur Administratoren, an Verbindungen, die sie sehen. */
    public function share(User $user, DatabaseConnection $connection): bool
    {
        return $user->isAdmin() && $this->view($user, $connection);
    }
}
