<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\SqlAssistant;
use App\Services\Auth\ActiveSessions;
use App\Support\RecentConfirmation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passkeys\Passkey;

/**
 * Konto (A-05): Sicherheit (Passkeys) und Darstellung. Keine Profilseite — Name und Adresse ändert die Verwaltung.
 */
class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('account/Show', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->label(),
                'isAdmin' => $user->isAdmin(),
                'memberSince' => $user->created_at?->toIso8601String(),
            ],
            'passkeys' => $user->passkeys()->latest()->get()->map(fn (Passkey $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'createdAt' => $passkey->created_at?->toIso8601String(),
                'lastUsedAt' => $passkey->last_used_at?->toIso8601String(),
            ])->values(),
            'canManagePasskeys' => RecentConfirmation::holds($request),
            'aiKey' => SqlAssistant::enabled() ? ['hint' => filled($user->ai_api_key) ? '…'.substr((string) $user->ai_api_key, -4) : null] : null,
            'sessions' => ActiveSessions::available() ? app(ActiveSessions::class)->of($user, $request->session()->getId()) : null,
        ]);
    }
}
