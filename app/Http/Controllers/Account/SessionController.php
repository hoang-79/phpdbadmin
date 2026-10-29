<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Auth\ActiveSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Aktive Sitzungen beenden (A-08): eine einzelne oder alle anderen. Die eigene beendet «Abmelden».
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly ActiveSessions $sessions,
        private readonly AuditLog $audit,
    ) {}

    public function destroy(Request $request, string $session): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(ActiveSessions::available(), 404);
        abort_unless($this->sessions->end($user, $session, $request->session()->getId()), 404);

        $this->audit->record('account.session_ended', $user, $user->email);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sitzung beendet.')]);

        return back();
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(ActiveSessions::available(), 404);

        $ended = $this->sessions->endOthers($user, $request->session()->getId());

        $this->audit->record('account.sessions_ended', $user, $user->email, ['count' => $ended]);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count andere Sitzung beendet.|:count andere Sitzungen beendet.', $ended)]);

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
