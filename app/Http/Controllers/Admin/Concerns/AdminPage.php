<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Gemeinsames der Verwaltungsseiten (Wireframe 14): Zähler für die Reiter und das angemeldete Konto.
 */
trait AdminPage
{
    /**
     * @return array{accounts: int, invitations: int, invitationDays: int}
     */
    protected function tabs(): array
    {
        return [
            'accounts' => User::query()->count(),
            'invitations' => Invitation::query()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            // Für «Person einladen» im Kopf jeder Verwaltungsseite.
            'invitationDays' => (int) config('phpdbadmin.login.invitation_days'),
        ];
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
