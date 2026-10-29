<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Eine geänderte Zeile mit Vorher- und Nachher-Werten (D-09). Nach der Frist (Vorgabe 7 Tage) weg.
 *
 * @property int $id
 * @property int $audit_log_id
 * @property int $user_id
 * @property string $connection_id
 * @property string|null $database
 * @property string $schema
 * @property string $target
 * @property int $position
 * @property 'insert'|'update'|'delete' $operation
 * @property list<string|null> $key
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $undone_at
 * @property CarbonImmutable|null $created_at
 */
class ChangeSnapshot extends Model
{
    use Prunable;

    public const null UPDATED_AT = null;

    protected $fillable = ['audit_log_id', 'user_id', 'connection_id', 'database', 'schema', 'target', 'position', 'operation', 'key', 'before', 'after', 'expires_at', 'undone_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => 'array',
            'before' => 'array',
            'after' => 'array',
            'expires_at' => 'immutable_datetime',
            'undone_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }
}
