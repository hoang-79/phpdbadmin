<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RecentConfirmation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Onboarding nach der ersten Anmeldung ohne Passkey: gleich einen Passkey anlegen (A-03). Das erste Konto der
 * Installation sieht die Ersteinrichtung mit den nächsten Schritten.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $continueUrl = (string) $request->session()->get('url.intended', route('home'));
        $continueUrl = $continueUrl === route('onboarding') ? route('home') : $continueUrl;
        $canRegister = RecentConfirmation::holds($request);

        // Bestätigung abgelaufen: nach dem Bestätigen zurück hierher, nicht ins Konto.
        if (! $canRegister) {
            redirect()->setIntendedUrl(route('onboarding'));
        }

        return Inertia::render('onboarding/Welcome', [
            'name' => $user->name,
            'email' => $user->email,
            'isFirstUser' => $user->isAdmin() && User::query()->count() === 1,
            'hasPasskey' => $user->hasPasskeysEnabled(),
            'canRegister' => $canRegister,
            'continueUrl' => $continueUrl,
        ]);
    }
}
