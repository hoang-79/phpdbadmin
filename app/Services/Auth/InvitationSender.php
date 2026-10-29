<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\Notification;

/**
 * Einladung ausstellen, per Mail senden und protokollieren (A-06) — für die Verwaltung im Browser und den Befehl
 * `phpdbadmin:invite`. Eine neue Einladung an dieselbe Adresse macht ältere offene ungültig.
 */
final class InvitationSender
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @return array{0: Invitation, 1: string} Einladung und Link (der Link nur jetzt im Klartext)
     */
    public function send(string $email, UserRole $role, ?User $invitedBy = null, string $action = 'account.invited'): array
    {
        [$invitation, $token] = Invitation::issue($email, $role, $invitedBy);
        $url = route('invitation.show', $token);

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification($url, $invitation->expires_at));

        $this->audit->record($action, $invitedBy, target: $invitation->email, context: ['role' => $role->value]);

        return [$invitation, $url];
    }
}
