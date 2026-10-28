<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RenamePasskeyRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Passkeys\Passkey;

/**
 * Passkey umbenennen (A-03). Hinzufügen und Entfernen übernimmt die Bibliothek (routes/auth.php).
 */
class PasskeyController extends Controller
{
    public function update(RenamePasskeyRequest $request, Passkey $passkey): RedirectResponse
    {
        abort_unless((int) $passkey->user_id === (int) $request->user()?->getAuthIdentifier(), 404);

        $passkey->update(['name' => $request->string('name')->trim()->toString()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Passkey umbenannt.']);

        return to_route('account');
    }
}
