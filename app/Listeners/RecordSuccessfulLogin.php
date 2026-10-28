<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Jede Anmeldung — per Code, Passkey oder Einladung — landet im Protokoll (A-09) und gilt zugleich als Bestätigung
 * für heikle Schritte wie das Verwalten von Passkeys.
 */
class RecordSuccessfulLogin
{
    private const array METHODS = [
        'login.code.verify' => 'code',
        'passkey.login' => 'passkey',
        'invitation.accept' => 'invitation',
    ];

    public function __construct(private readonly Request $request, private readonly AuditLog $audit) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($this->request->hasSession()) {
            $this->request->session()->passwordConfirmed();
        }

        $this->audit->record('auth.login', $event->user, context: [
            'method' => self::METHODS[$this->request->route()?->getName() ?? ''] ?? 'other',
        ]);
    }
}
