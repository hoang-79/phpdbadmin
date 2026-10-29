<?php

namespace App\Services\Setup;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Translations;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Das erste Konto (A-06): genau ein Administrator, nur solange es noch kein Konto gibt, dazu der Einrichtungslink, der
 * bis zur ersten Anmeldung gilt und direkt ins Onboarding mit Passkey führt. Gemeinsam für `phpdbadmin:install` und
 * den Web-Installer.
 */
final class FirstAccount
{
    /** Gültigkeit des Einrichtungslinks in Minuten. */
    public const int LINK_MINUTES = 30;

    public function __construct(private readonly AuditLog $audit) {}

    public function exists(): bool
    {
        return User::query()->exists();
    }

    /**
     * @throws ValidationException
     */
    public function create(string $name, string $email, ?string $locale = null): User
    {
        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']],
        );
        $validator->validate();

        $user = new User([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'role' => UserRole::Admin,
        ]);

        if ($locale !== null && Translations::isSupported($locale)) {
            $user->locale = $locale;
        }

        $user->save();
        $this->audit->record('account.installed', $user, target: $user->email);

        return $user;
    }

    /**
     * Die Ersteinrichtung wurde nie abgeschlossen: genau ein Konto, ein Administrator, nie angemeldet.
     */
    public function unfinishedAdmin(): ?User
    {
        $admin = User::query()->where('role', UserRole::Admin->value)->oldest('id')->first();

        return User::query()->count() === 1 && $admin !== null && $admin->last_login_at === null ? $admin : null;
    }

    public function setupUrl(User $user): string
    {
        return URL::temporarySignedRoute('setup.show', now()->addMinutes(self::LINK_MINUTES), ['user' => $user->id]);
    }
}
