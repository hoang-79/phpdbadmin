<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Translations;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprache je Anfrage (N-06): die Wahl im Konto, sonst die erste passende Sprache des Browsers, sonst Deutsch.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        app()->setLocale($user instanceof User && Translations::isSupported($user->locale)
            ? $user->locale
            : ($request->getPreferredLanguage(Translations::LOCALES) ?? Translations::LOCALES[0]));

        return $next($request);
    }
}
