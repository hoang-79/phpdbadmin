<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Eintrag im Protokoll (A-09, B-04): wer, wann, welche Verbindung, was — bei schreibenden Aktionen mit der
 * Anweisung (Werte als Platzhalter) und der Zahl betroffener Zeilen. Einträge werden nur angelegt, nie geändert,
 * und nach der Frist der Installation gelöscht (`phpdbadmin.audit.retention_days`, täglicher `model:prune`).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string|null $connection_id
 * @property string|null $connection_name
 * @property string $action
 * @property string|null $target
 * @property string|null $sql
 * @property int|null $affected_rows
 * @property array<string, mixed>|null $context
 * @property string|null $ip_hash
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 * @property-read DatabaseConnection|null $connection
 */
class AuditEntry extends Model
{
    use Prunable;

    public const null UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = [
        'user_id', 'user_name', 'connection_id', 'connection_name', 'action', 'target', 'sql', 'affected_rows', 'context',
        'ip_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'affected_rows' => 'integer',
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Die Verbindung, solange es sie gibt; danach bleibt `connection_name`.
     *
     * @return BelongsTo<DatabaseConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class, 'connection_id');
    }

    /**
     * Aufbewahrung (B-04, Datenmodell § 4): älter als die Frist wird gelöscht; eine Frist von 0 behält alles.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = self::retentionDays();

        return $days > 0
            ? self::query()->where('created_at', '<', now()->subDays($days))
            : self::query()->whereRaw('1 = 0');
    }

    public static function retentionDays(): int
    {
        return max(0, (int) config('phpdbadmin.audit.retention_days'));
    }
}
