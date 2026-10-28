<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Passkey hinzugefügt oder entfernt: ein Eintrag im Protokoll.
 */
class RecordPasskeyChange
{
    public function __construct(private readonly AuditLog $audit) {}

    public function handle(PasskeyRegistered|PasskeyDeleted $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->audit->record(
            $event instanceof PasskeyRegistered ? 'passkey.registered' : 'passkey.deleted',
            $user,
            target: $event->passkey->name,
        );
    }
}
