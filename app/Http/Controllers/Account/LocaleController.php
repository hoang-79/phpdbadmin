<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprache des Kontos (N-06): gilt für Oberfläche und Mails.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $user->locale = (string) $request->validate(['locale' => ['required', Rule::in(Translations::LOCALES)]])['locale'];
        $user->save();

        return back();
    }
}
