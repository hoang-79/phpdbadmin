<?php

namespace App\Connections;

use App\Models\DatabaseConnection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * Schreibschutz auf Zeit aufheben (V-11): eine schreibgeschützte Verbindung ist für diese Sitzung 15 Minuten
 * beschreibbar, mit Pflichtgrund und jederzeit wieder sperrbar. TargetConnections fragt hier nach, deshalb gilt die
 * Freigabe überall — Bearbeiten, SQL-Editor, Struktur, Server. Recht und Protokoll prüft der Controller.
 */
final class WriteUnlock
{
    private const string SESSION_KEY = 'schreibfreigabe';

    public function __construct(private readonly Session $session) {}

    public function until(DatabaseConnection $connection): ?CarbonImmutable
    {
        $stored = $this->session->get(self::SESSION_KEY.'.'.$connection->id);
        $until = is_array($stored) && is_string($stored['until'] ?? null) ? CarbonImmutable::parse($stored['until']) : null;

        return $until !== null && $until->isFuture() ? $until : null;
    }

    public function isActive(DatabaseConnection $connection): bool
    {
        return $connection->read_only && $this->until($connection) !== null;
    }

    public function unlock(DatabaseConnection $connection, string $reason): CarbonImmutable
    {
        $until = CarbonImmutable::now()->addMinutes((int) config('phpdbadmin.data.unlock_minutes', 15));
        $this->session->put(self::SESSION_KEY.'.'.$connection->id, ['until' => $until->toIso8601String(), 'reason' => $reason]);

        return $until;
    }

    public function lock(DatabaseConnection $connection): void
    {
        $this->session->forget(self::SESSION_KEY.'.'.$connection->id);
    }
}
