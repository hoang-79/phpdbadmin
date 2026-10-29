<?php

namespace App\Policies;

use App\Models\DatabaseConnection;
use App\Models\User;

/**
 * Rechte an einer Verbindung (A-07). Vorläufig nur «schreiben» für den Import (E-02): Besitzer oder Recht
 * «verwalten». Die vollständige Policy (ansehen, schreiben, verwalten je Recht) folgt mit TB 07 und ersetzt diese.
 */
class DatabaseConnectionPolicy
{
    public function write(User $user, DatabaseConnection $connection): bool
    {
        return $connection->isManageableBy($user);
    }
}
