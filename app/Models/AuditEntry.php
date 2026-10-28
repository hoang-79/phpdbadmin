<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Eintrag im Protokoll (A-09, später B-04): wer, wann, was. Einträge werden nur angelegt, nie geändert.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $target
 * @property string|null $sql
 * @property int|null $affected_rows
 * @property array<string, mixed>|null $context
 * @property string|null $ip_hash
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
class AuditEntry extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = ['user_id', 'action', 'target', 'sql', 'affected_rows', 'context', 'ip_hash'];

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
}
