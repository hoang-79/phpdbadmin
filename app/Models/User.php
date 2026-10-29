<?php

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;

/**
 * Konto ohne Passwort (A-01): Anmeldung nur per Code an die Adresse oder per Passkey; kein «angemeldet bleiben».
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property string $locale
 * @property string $theme
 * @property string $density
 * @property string $timezone
 * @property string|null $ai_api_key
 * @property array<string, mixed>|null $preferences
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, DatabaseConnection> $ownedConnections
 */
#[Fillable(['name', 'email', 'role'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * Keine Spalte für «angemeldet bleiben» (Datenmodell § 1): leer schaltet das Speichern des Tokens ab.
     *
     * @var string
     */
    protected $rememberTokenName = '';

    /**
     * Der eigene Schlüssel für die KI-Hilfe (Q-08) verlässt den Server nur in Richtung des Anbieters.
     *
     * @var list<string>
     */
    protected $hidden = ['ai_api_key'];

    /**
     * Vorgaben wie in der Migration — sonst fehlen sie einem frisch angelegten Modell bis zum Neuladen.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'locale' => 'de',
        'theme' => 'system',
        'density' => 'default',
        'timezone' => 'Europe/Zurich',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'ai_api_key' => 'encrypted',
            'preferences' => 'array',
        ];
    }

    /**
     * Mails in der Sprache des Kontos (N-06).
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Gesperrt durch die Verwaltung: keine Anmeldung, laufende Sitzungen enden (EnsureAccountIsActive). */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Verbindungen, die diesem Konto gehören — persönliche und geteilte.
     *
     * @return HasMany<DatabaseConnection, $this>
     */
    public function ownedConnections(): HasMany
    {
        return $this->hasMany(DatabaseConnection::class, 'owner_id');
    }

    /** Das einzige nicht gesperrte Administratorkonto — es bleibt, wie es ist (UserPolicy). */
    public function isLastActiveAdmin(): bool
    {
        return $this->isAdmin() && ! $this->isDisabled()
            && ! self::query()->activeAdmins()->whereKeyNot($this->id)->exists();
    }

    /**
     * Konten mit der Rolle Administrator (A-07) — es muss immer mindestens eines aktiv bleiben.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveAdmins(Builder $query): Builder
    {
        return $query->where('role', UserRole::Admin->value)->whereNull('disabled_at');
    }
}
