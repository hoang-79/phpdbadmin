<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Services\Audit\AuditLog;
use App\Services\Auth\LoginCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/**
 * Bestätigen vor heiklen Schritten (ersetzt die Passwortbestätigung, F-6): per Passkey (Bibliothek) oder per Code an
 * die eigene Adresse. Die Middleware `password.confirm` leitet hierher.
 */
class ConfirmController extends Controller
{
    public function __construct(private readonly LoginCodes $codes, private readonly AuditLog $audit) {}

    public function show(Request $request): Response
    {
        // Ziel nach dem Bestätigen: nie eine Passkey-Aktion selbst (PATCH/DELETE ergäbe nach der Weiterleitung 405).
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended) || str_contains($intended, '/account/passkeys/')) {
            redirect()->setIntendedUrl(route('account'));
        }

        return Inertia::render('auth/Confirm', [
            'email' => $this->user($request)->email,
            'hasPasskeys' => $this->user($request)->hasPasskeysEnabled(),
            'codeSent' => $request->session()->has('confirm.sent_at'),
        ]);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $code = $this->codes->issue($user->email, LoginCode::PURPOSE_CONFIRM, $request->ip());
        $minutes = (int) config('phpdbadmin.login.code_minutes');

        $request->session()->put('confirm.sent_at', now()->getTimestamp());

        defer(fn () => $user->notify(new LoginCodeNotification($code, LoginCode::PURPOSE_CONFIRM, $minutes)));

        return to_route('password.confirm');
    }

    public function verifyCode(VerifyCodeRequest $request): RedirectResponse
    {
        $user = $this->user($request);

        if (! $this->codes->consume($user->email, LoginCode::PURPOSE_CONFIRM, $request->code())) {
            $this->audit->record('auth.failed', $user, context: ['method' => 'confirm-code']);

            throw ValidationException::withMessages([
                'code' => __('Der Code stimmt nicht oder ist abgelaufen.'),
            ]);
        }

        $request->session()->forget('confirm.sent_at');
        $request->session()->passwordConfirmed();

        return redirect()->intended(route('account'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
