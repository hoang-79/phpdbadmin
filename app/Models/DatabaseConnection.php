<?php

namespace App\Models;

use App\Enums\ConnectionEnvironment;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use Carbon\CarbonImmutable;
use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Eine gespeicherte Zielverbindung (V-01). Das Passwort ist verschlüsselt und verlässt den Server nie (V-03).
 *
 * @property string $id
 * @property int $owner_id
 * @property bool $shared
 * @property string $name
 * @property string|null $group
 * @property DatabaseDriver $driver
 * @property string|null $host
 * @property int|null $port
 * @property string|null $database
 * @property string|null $username
 * @property string|null $password
 * @property bool $store_password
 * @property string|null $sqlite_path
 * @property array{sslmode?: string}|null $options
 * @property ConnectionEnvironment $environment
 * @property bool $read_only
 * @property string|null $server_version
 * @property list<array<string, string>>|null $account_warnings
 * @property CarbonImmutable|null $last_tested_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $owner
 */
#[Hidden(['password'])]
class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory, HasUlids;

    protected $table = 'connections';

    protected $fillable = [
        'shared', 'name', 'group', 'driver', 'host', 'port', 'database', 'username', 'password', 'store_password',
        'sqlite_path', 'options', 'environment', 'read_only', 'server_version', 'account_warnings', 'last_tested_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shared' => 'boolean',
            'driver' => DatabaseDriver::class,
            'port' => 'integer',
            'password' => 'encrypted',
            'store_password' => 'boolean',
            'options' => 'array',
            'environment' => ConnectionEnvironment::class,
            'read_only' => 'boolean',
            'account_warnings' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Konten mit Recht an einer geteilten Verbindung (A-07, V-09).
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'connection_user', 'connection_id')
            ->withPivot(['permission', 'favorite', 'last_opened_at']);
    }

    /**
     * Was ein Konto sieht: eigene Verbindungen und geteilte, an denen es ein Recht hat (V-09). Alles andere ist
     * für dieses Konto nicht vorhanden (404, T-16).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $visible) => $visible
            ->where('owner_id', $user->id)
            ->orWhere(fn (Builder $shared) => $shared
                ->where('shared', true)
                ->whereHas('members', fn (Builder $member) => $member->where('users.id', $user->id))));
    }

    /** Bearbeiten und Löschen: Besitzer oder Recht «verwalten». */
    public function isManageableBy(User $user): bool
    {
        if ($this->owner_id === $user->id) {
            return true;
        }

        return $this->shared && $this->members()
            ->where('users.id', $user->id)
            ->wherePivot('permission', ConnectionPermission::Manage->value)
            ->exists();
    }

    public function sslMode(): SslMode
    {
        return SslMode::tryFrom((string) ($this->options['sslmode'] ?? '')) ?? SslMode::Disable;
    }
}
