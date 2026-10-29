<?php

namespace App\Policies;

use App\Enums\ConnectionPermission;
use App\Models\DatabaseConnection;
use App\Models\User;

/**
 * Rechte je Verbindung (A-07): lesen ⊂ schreiben ⊂ verwalten. Vorläufig aus dem Hauptstrang für den SQL-Editor —
 * die vollständige Fassung mit Teilen und Verwaltung kommt mit TB 07 und ersetzt diese.
 */
class DatabaseConnectionPolicy
{
    public function write(User $user, DatabaseConnection $connection): bool
    {
        if ($connection->isManageableBy($user)) {
            return true;
        }

        return $connection->shared && $connection->members()
            ->where('users.id', $user->id)
            ->wherePivotIn('permission', [ConnectionPermission::Write->value, ConnectionPermission::Manage->value])
            ->exists();
    }

    public function manage(User $user, DatabaseConnection $connection): bool
    {
        return $connection->isManageableBy($user);
    }
}
