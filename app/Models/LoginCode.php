<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Sechsstelliger Code per Mail (A-02). Adresse und Code liegen nur als Prüfsumme vor; unbekannte Adressen bekommen
 * keinen Datensatz.
 *
 * @property int $id
 * @property string $purpose
 * @property string $email_hash
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property string|null $ip_hash
 * @property CarbonImmutable $created_at
 */
class LoginCode extends Model
{
    /** Anmelden. */
    public const string PURPOSE_LOGIN = 'login';

    /** Bestätigen vor einem heiklen Schritt (Passkeys verwalten). */
    public const string PURPOSE_CONFIRM = 'confirm';

    public const null UPDATED_AT = null;

    protected $fillable = ['purpose', 'email_hash', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'ip_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('phpdbadmin.login.code_attempts');
    }
}
