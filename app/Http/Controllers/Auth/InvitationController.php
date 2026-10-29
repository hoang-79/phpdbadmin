<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einladung annehmen (A-06): Name angeben, Konto entsteht, Anmeldung folgt sofort — weiter ins Onboarding, das
 * gleich den ersten Passkey anlegt.
 */
class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = Invitation::findByToken($token);

        return Inertia::render('auth/Invitation', [
            'token' => $token,
            'invitation' => $invitation?->isOpen() ? [
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $invitation = Invitation::findByToken($token);

        $user = $invitation === null ? null : DB::transaction(function () use ($invitation, $request): ?User {
            $claimed = Invitation::query()
                ->whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            return User::query()->firstOrCreate(
                ['email' => $invitation->email],
                ['name' => $request->string('name')->trim()->toString(), 'role' => $invitation->role],
            );
        });

        if ($user === null || $user->isDisabled()) {
            throw ValidationException::withMessages([
                'name' => __('Diese Einladung ist abgelaufen oder wurde schon angenommen.'),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('onboarding');
    }
}
