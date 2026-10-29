<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Aktive Sitzungen eines Kontos (A-08): sehen und beenden. Geht nur mit dem Sitzungstreiber `database` — dort liegt
 * jede Sitzung als Zeile mit Konto, Adresse, Browser und letzter Aktivität. Eine gelöschte Zeile ist eine beendete
 * Sitzung: Der nächste Aufruf mit ihrem Cookie findet nichts und landet bei der Anmeldung (phpdbadmin kennt kein
 * «angemeldet bleiben»).
 *
 * Die Kennung einer Sitzung ist ein Geheimnis — der Browser bekommt nur einen HMAC davon.
 *
 * @phpstan-type SessionSummary array{key: string, current: bool, ip: string|null, browser: string|null, os: string|null, lastActiveAt: string}
 */
final class ActiveSessions
{
    public static function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return list<SessionSummary>
     */
    public function of(User $user, string $currentId): array
    {
        if (! self::available()) {
            return [];
        }

        $sessions = $this->rows($user)
            ->map(fn (object $row): array => [
                'key' => $this->key((string) $row->id),
                'current' => hash_equals((string) $row->id, $currentId),
                'ip' => $row->ip_address === null ? null : (string) $row->ip_address,
                'browser' => self::browser((string) $row->user_agent),
                'os' => self::system((string) $row->user_agent),
                'lastActiveAt' => CarbonImmutable::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
            ])
            ->sortByDesc(fn (array $session): string => ($session['current'] ? '1' : '0').$session['lastActiveAt'])
            ->all();

        return array_values($sessions);
    }

    /** Beendet eine fremde Sitzung des Kontos — nie die eigene (dafür gibt es «Abmelden»). */
    public function end(User $user, string $key, string $currentId): bool
    {
        foreach ($this->rows($user) as $row) {
            if (hash_equals($this->key((string) $row->id), $key) && ! hash_equals((string) $row->id, $currentId)) {
                return DB::table($this->table())->where('id', $row->id)->delete() === 1;
            }
        }

        return false;
    }

    /** Beendet alle Sitzungen des Kontos ausser der aktuellen; gibt ihre Zahl zurück. */
    public function endOthers(User $user, string $currentId): int
    {
        return DB::table($this->table())->where('user_id', $user->id)->where('id', '!=', $currentId)->delete();
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function rows(User $user): Collection
    {
        return DB::table($this->table())
            ->where('user_id', $user->id)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function key(string $id): string
    {
        return substr(hash_hmac('sha256', $id, (string) config('app.key')), 0, 32);
    }

    /** Grob, aber ohne Bibliothek: Reihenfolge zählt (Edge und Opera nennen sich auch Chrome, Chrome nennt sich auch Safari). */
    public static function browser(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    public static function system(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
