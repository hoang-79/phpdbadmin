<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Eine ausgeführte Anweisung im Verlauf des SQL-Editors (Q-04). Nur das eigene Konto sieht sie; nach 90 Tagen weg.
 *
 * @property int $id
 * @property int $user_id
 * @property string $connection_id
 * @property string $sql
 * @property string $status
 * @property int|null $duration_ms
 * @property int|null $row_count
 * @property CarbonImmutable|null $created_at
 */
class QueryHistoryEntry extends Model
{
    use Prunable;

    public const null UPDATED_AT = null;

    protected $table = 'query_history';

    protected $fillable = ['user_id', 'connection_id', 'sql', 'status', 'duration_ms', 'row_count'];

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('phpdbadmin.editor.history_days', 90)));
    }
}
