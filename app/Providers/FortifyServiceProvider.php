<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

/**
 * Fortify nur noch für die Passkey-Zeremonie (ADR-008).
 *
 * Fortify registriert sonst unabhängig von den Features `POST /login` (Passwort) und die Passwortbestätigung.
 * Deshalb übernimmt die Anwendung die Routen selbst (routes/auth.php) und registriert nur die Passkey-Wege —
 * einen Passwort-Endpunkt gibt es damit gar nicht (A-01).
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        // Gesperrte Konten (Verwaltung) melden sich auch per Passkey nicht an.
        Passkeys::authorizeLoginUsing(fn (Request $request, mixed $user): bool => ! ($user instanceof User && $user->isDisabled()));
    }

    private function configureRateLimiting(): void
    {
        // Code anfordern: je Adresse und IP fünf je Minute, je IP höchstens zwanzig (gegen Durchprobieren vieler Adressen).
        RateLimiter::for('login-code', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login-code:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-code-ip:'.$request->ip()),
            ];
        });

        // Code eingeben: zehn Versuche je Minute und Sitzung; der Code selbst sperrt nach fünf Fehlversuchen.
        RateLimiter::for('login-verify', function (Request $request) {
            return Limit::perMinute(10)->by('login-verify:'.$request->session()->getId().'|'.$request->ip());
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                'passkeys:'.($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
