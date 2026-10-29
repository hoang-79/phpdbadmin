<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\SqlAssistant;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Eigener Schlüssel für die SQL-Hilfe mit KI (Q-08): hinterlegen und entfernen. Gespeichert verschlüsselt; der
 * Browser sieht danach nur die letzten vier Zeichen.
 */
class AiKeyController extends Controller
{
    public function __construct(private readonly AuditLog $audit) {}

    public function update(Request $request): RedirectResponse
    {
        abort_unless(SqlAssistant::enabled(), 404);
        $user = $this->user($request);

        $validated = $request->validate(['key' => ['required', 'string', 'min:20', 'max:400', 'regex:/^\S+$/']], [], ['key' => __('Schlüssel')]);

        $user->forceFill(['ai_api_key' => $validated['key']])->save();
        $this->audit->record('account.ai_key_set', $user, $user->email);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Schlüssel für die KI-Hilfe gespeichert.')]);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $user->forceFill(['ai_api_key' => null])->save();
        $this->audit->record('account.ai_key_removed', $user, $user->email);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Schlüssel für die KI-Hilfe entfernt.')]);

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
