<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Fingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Einladung statt offener Registrierung (A-06). Der Link trägt ein zufälliges Token; gespeichert ist nur seine
 * Prüfsumme.
 *
 * @property int $id
 * @property string $email
 * @property UserRole $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $inviter
 */
class Invitation extends Model
{
    protected $fillable = ['email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Legt eine Einladung an; offene Einladungen an dieselbe Adresse verfallen.
     *
     * @return array{0: self, 1: string} Einladung und Token im Klartext (nur jetzt sichtbar)
     */
    public static function issue(string $email, UserRole $role, ?User $invitedBy = null): array
    {
        $email = mb_strtolower(trim($email));
        $token = Str::random(48);

        self::query()->where('email', $email)->whereNull('accepted_at')->update(['expires_at' => now()]);

        $invitation = self::query()->create([
            'email' => $email,
            'role' => $role,
            'token_hash' => Fingerprint::of('invitation:'.$token),
            'invited_by' => $invitedBy?->id,
            'expires_at' => now()->addDays((int) config('phpdbadmin.login.invitation_days')),
        ]);

        return [$invitation, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', Fingerprint::of('invitation:'.$token))->first();
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
