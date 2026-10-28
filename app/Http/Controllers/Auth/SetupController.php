<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ersteinrichtung (A-06): `phpdbadmin:install` legt das erste Administratorkonto an und gibt einen signierten Link
 * aus. Er gilt 30 Minuten und nur, bis sich das Konto zum ersten Mal angemeldet hat — danach ist er wertlos. So
 * gelingt die erste Anmeldung auch, wenn der Server noch keine Mail versenden kann; das Onboarding legt danach gleich
 * den Passkey an.
 *
 * Der Link öffnet zuerst eine Seite mit Knopf (GET), erst das Absenden meldet an (POST) — Vorschauen von Links in
 * Chats oder Mailprogrammen verbrauchen ihn so nicht.
 */
class SetupController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        $isOpen = $this->isOpen($request, $user);

        return Inertia::render('auth/Setup', [
            'setup' => $isOpen ? [
                'name' => $user->name,
                'email' => $user->email,
                'action' => $request->fullUrl(),
            ] : null,
        ]);
    }

    public function start(Request $request, User $user): RedirectResponse
    {
        if (! $this->isOpen($request, $user)) {
            return redirect()->to($request->fullUrl());
        }

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('onboarding');
    }

    private function isOpen(Request $request, User $user): bool
    {
        return $request->hasValidSignature() && $user->isAdmin() && $user->last_login_at === null;
    }
}
