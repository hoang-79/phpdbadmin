<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Lebenszeichen der Warteschlange (B-07): Ein Worker meldet sich bei jedem Versuch, einen Auftrag zu holen. Nur wenn
 * das letzte Zeichen jünger als `phpdbadmin.transfers.worker_seconds` ist, gehen Aufträge in die Warteschlange —
 * sonst blieben sie in einer Installation ohne Worker für immer «wartet» (Laravels Vorgabe ist QUEUE_CONNECTION=database).
 */
class QueueHeartbeat
{
    private const string KEY = 'phpdbadmin:queue-heartbeat';

    /** Ein wartender Worker fragt alle drei Sekunden — in den Cache geschrieben wird höchstens alle 15 Sekunden. */
    private const int THROTTLE_SECONDS = 15;

    private int $lastBeat = 0;

    public function beat(): void
    {
        $now = Date::now()->getTimestamp();

        if ($now - $this->lastBeat < self::THROTTLE_SECONDS) {
            return;
        }

        $this->lastBeat = $now;
        Cache::put(self::KEY, $now, 3600);
    }

    public function isAlive(): bool
    {
        $last = Cache::get(self::KEY);

        return is_int($last) && Date::now()->getTimestamp() - $last <= (int) config('phpdbadmin.transfers.worker_seconds', 90);
    }
}
