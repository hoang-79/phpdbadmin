<?php

namespace App\Models;

use App\Enums\ConnectionPermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Zeile in `connection_user`: Recht eines Kontos an einer geteilten Verbindung (A-07, V-09) und seine persönlichen
 * Merker (Favorit, zuletzt geöffnet — V-06). Besitzer und Administratoren haben eine Zeile nur für ihre Merker; sie
 * trägt ihr wirksames Recht «verwalten».
 *
 * @property string $connection_id
 * @property int $user_id
 * @property ConnectionPermission $permission
 * @property bool $favorite
 * @property CarbonImmutable|null $last_opened_at
 * @property-read User $user
 */
class ConnectionMembership extends Pivot
{
    protected $table = 'connection_user';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permission' => ConnectionPermission::class,
            'favorite' => 'boolean',
            'last_opened_at' => 'datetime',
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
