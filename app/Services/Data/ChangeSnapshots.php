<?php

namespace App\Services\Data;

use App\Models\ChangeSnapshot;
use App\Models\DatabaseConnection;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Vorher- und Nachher-Werte für «Rückgängig» (D-09) — gemeinsam für das Bearbeiten (RowEditor) und Suchen und
 * Ersetzen (SearchReplace). Ganze Zeilen, so gespeichert, dass sie genau so wieder geschrieben werden können
 * (Binärdaten als Base64). Bis zur Grenze `phpdbadmin.data.undo_rows` Zeilen je Änderung, Frist `undo_days`.
 *
 * @phpstan-type Snapshot array{position: int, operation: 'insert'|'update'|'delete', key: list<string|null>, before: array<string, mixed>|null, after: array<string, mixed>|null}
 */
final class ChangeSnapshots
{
    /** Bis zu so vielen Zeilen je Änderung lässt sich rückgängig machen. */
    public static function limit(): int
    {
        return (int) config('phpdbadmin.data.undo_rows', 1000);
    }

    /**
     * Sichern, wenn es nicht zu viele sind; sagt, ob die Änderung umkehrbar ist.
     *
     * @param  list<Snapshot>  $snapshots
     */
    public function store(int $auditId, User $user, DatabaseConnection $connection, ?string $database, string $schema, string $table, array $snapshots): bool
    {
        if (count($snapshots) > self::limit()) {
            return false;
        }

        $expires = CarbonImmutable::now()->addDays((int) config('phpdbadmin.data.undo_days', 7));

        foreach ($snapshots as $snapshot) {
            ChangeSnapshot::query()->create([
                ...$snapshot,
                'audit_log_id' => $auditId,
                'user_id' => $user->id,
                'connection_id' => $connection->id,
                'database' => $database,
                'schema' => $schema,
                'target' => $table,
                'expires_at' => $expires,
            ]);
        }

        return true;
    }

    /**
     * Zeile so, dass sie als JSON gespeichert und genau so wieder geschrieben werden kann: Binärdaten als Base64,
     * Ströme gelesen.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function portable(array $row): array
    {
        return array_map(function (mixed $value): mixed {
            if (is_resource($value)) {
                $value = (string) stream_get_contents($value);
            }

            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                return ['$base64' => base64_encode($value)];
            }

            return $value;
        }, $row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function restorable(array $row): array
    {
        return array_map(fn (mixed $value): mixed => is_array($value) && isset($value['$base64']) ? base64_decode((string) $value['$base64']) : $value, $row);
    }
}
