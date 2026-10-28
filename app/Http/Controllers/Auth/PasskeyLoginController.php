<?php

namespace App\Http\Controllers\Auth;

use App\Services\Audit\AuditLog;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController as BasePasskeyLoginController;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

/**
 * Anmeldung per Passkey aus der Bibliothek, ergänzt um den Protokolleintrag für Fehlversuche (A-09).
 */
class PasskeyLoginController extends BasePasskeyLoginController
{
    public function store(PasskeyVerificationRequest $request, VerifyPasskey $verify): PasskeyLoginResponse
    {
        try {
            return parent::store($request, $verify);
        } catch (InvalidPasskeyException $exception) {
            app(AuditLog::class)->record('auth.failed', context: ['method' => 'passkey']);

            throw $exception;
        }
    }
}
