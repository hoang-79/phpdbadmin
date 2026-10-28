<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Hat die Sitzung kürzlich bestätigt (Anmeldung, Passkey oder Code an die eigene Adresse)? Gleiche Frist wie die
 * Middleware `password.confirm` (auth.password_timeout).
 */
final class RecentConfirmation
{
    public static function holds(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return now()->getTimestamp() - $confirmedAt < (int) config('auth.password_timeout', 10800);
    }
}
