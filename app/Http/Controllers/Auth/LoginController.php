<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestCodeRequest;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Services\Audit\AuditLog;
use App\Services\Auth\LoginCodes;
use App\Support\Fingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/**
 * Anmeldung mit Code per Mail (A-02). Bekannte und unbekannte Adressen bekommen dieselbe Antwort; die Mail geht
 * erst nach der Antwort hinaus, damit auch die Antwortzeit nichts verrät.
 */
class LoginController extends Controller
{
    public function __construct(private readonly LoginCodes $codes, private readonly AuditLog $audit) {}

    public function show(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'email' => $request->session()->get('login.email'),
        ]);
    }

    public function requestCode(RequestCodeRequest $request): RedirectResponse
    {
        $this->sendCode($request, $request->email());

        return to_route('login.code.show');
    }

    public function showCode(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get('login.email');

        if (! is_string($email)) {
            return to_route('login');
        }

        return Inertia::render('auth/Code', [
            'email' => $email,
            'validMinutes' => (int) config('phpdbadmin.login.code_minutes'),
            'resendInSeconds' => $this->secondsUntilResend($request),
        ]);
    }

    public function verifyCode(VerifyCodeRequest $request): RedirectResponse
    {
        $email = $request->session()->get('login.email');

        if (! is_string($email)) {
            return to_route('login');
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! $this->codes->consume($email, LoginCode::PURPOSE_LOGIN, $request->code())) {
            $this->audit->record('auth.failed', $user, context: [
                'method' => 'code',
                'email_hash' => Fingerprint::ofEmail($email),
            ]);

            throw ValidationException::withMessages([
                'code' => 'Der Code stimmt nicht oder ist abgelaufen. Fordern Sie bei Bedarf einen neuen an.',
            ]);
        }

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->forget(['login.email', 'login.sent_at']);

        return redirect()->intended(route('home'));
    }

    public function resendCode(Request $request): RedirectResponse
    {
        $email = $request->session()->get('login.email');

        if (! is_string($email)) {
            return to_route('login');
        }

        if ($this->secondsUntilResend($request) > 0) {
            throw ValidationException::withMessages([
                'code' => 'Ein neuer Code lässt sich erst in '.$this->secondsUntilResend($request).' Sekunden senden.',
            ]);
        }

        $this->sendCode($request, $email);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Neuer Code gesendet.']);

        return to_route('login.code.show');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->audit->record('auth.logout', $request->user() instanceof User ? $request->user() : null);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }

    private function sendCode(Request $request, string $email): void
    {
        $request->session()->put('login.email', $email);
        $request->session()->put('login.sent_at', now()->getTimestamp());

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $code = $this->codes->issue($email, LoginCode::PURPOSE_LOGIN, $request->ip());
        $minutes = (int) config('phpdbadmin.login.code_minutes');

        defer(fn () => $user->notify(new LoginCodeNotification($code, LoginCode::PURPOSE_LOGIN, $minutes)));
    }

    private function secondsUntilResend(Request $request): int
    {
        $sentAt = (int) $request->session()->get('login.sent_at', 0);

        return max(0, $sentAt + (int) config('phpdbadmin.login.resend_seconds') - now()->getTimestamp());
    }
}
