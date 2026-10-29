<?php

namespace App\Services\Audit;

use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Support\Fingerprint;
use Illuminate\Http\Request;
use Throwable;

/**
 * Schreibt Einträge ins Protokoll (A-09, B-04). IP-Adressen und fremde E-Mail-Adressen stehen nur als Prüfsumme
 * darin; Anweisungen und Fehlermeldungen nur maskiert — gebundene Werte und Literale werden zu `?` (Masking).
 * Name von Konto und Verbindung stehen mit im Eintrag, damit er lesbar bleibt, wenn beide später entfernt werden.
 *
 * Schreibende Aktionen auf Zielverbindungen rufen auf mit `connection`, `target` (Schema.Tabelle), `sql` samt
 * `bindings` und `affectedRows`; scheitert die Aktion, mit `error` (die Ausnahme oder ihre Meldung).
 */
final class AuditLog
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<array-key, mixed>  $bindings  Werte zur Anweisung — sie erscheinen in keinem Feld des Eintrags
     */
    public function record(
        string $action,
        ?User $user = null,
        ?string $target = null,
        array $context = [],
        ?DatabaseConnection $connection = null,
        ?string $sql = null,
        ?int $affectedRows = null,
        array $bindings = [],
        Throwable|string|null $error = null,
    ): AuditEntry {
        if ($error !== null) {
            $context['error'] = $error instanceof Throwable
                ? Masking::exception($error)
                : Masking::message($error, $bindings);
        }

        return AuditEntry::query()->create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'connection_id' => $connection?->id,
            'connection_name' => $connection?->name,
            'action' => $action,
            'target' => $target,
            'sql' => $sql === null ? null : Masking::sql($sql, $bindings, $this->doubleQuotedStrings($connection)),
            'affected_rows' => $affectedRows,
            'context' => $context === [] ? null : $context,
            'ip_hash' => Fingerprint::ofIp($this->request->ip()),
        ]);
    }

    /** MySQL und MariaDB lesen "…" als Zeichenkette (ohne ANSI_QUOTES), die anderen als Bezeichner. */
    private function doubleQuotedStrings(?DatabaseConnection $connection): bool
    {
        return in_array($connection?->driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true);
    }
}
