<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ein gesperrtes Konto (Verwaltung) ist mit der nächsten Anfrage abgemeldet — gleich, mit welchem Weg es sich
 * angemeldet hatte und welcher Sitzungstreiber läuft.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDisabled()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401);
            }

            return to_route('login')->withErrors(['email' => __('Dieses Konto ist gesperrt. Wenden Sie sich an eine Administratorin oder einen Administrator.')]);
        }

        return $next($request);
    }
}
