<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Auth\InvitationSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einladungen in der Verwaltung (A-06, Wireframe 14): einladen mit Ablauf, erneut senden, zurückziehen. Der Link
 * erscheint nach dem Senden einmal auf der Seite — für den Fall, dass die Mail nicht ankommt.
 */
class InvitationController extends Controller
{
    use AdminPage;

    public function index(): Response
    {
        return Inertia::render('admin/Invitations', [
            'tabs' => $this->tabs(),
            'invitations' => Invitation::query()->with('inviter:id,name')->latest('id')->limit(100)->get()
                ->map(fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'roleLabel' => $invitation->role->label(),
                    'invitedBy' => $invitation->inviter?->name,
                    'createdAt' => $invitation->created_at?->toIso8601String(),
                    'expiresAt' => $invitation->expires_at->toIso8601String(),
                    'acceptedAt' => $invitation->accepted_at?->toIso8601String(),
                    'state' => match (true) {
                        $invitation->accepted_at !== null => 'accepted',
                        $invitation->isOpen() => 'open',
                        default => 'expired',
                    },
                ])->values(),
        ]);
    }

    public function store(InviteRequest $request, InvitationSender $invitations): RedirectResponse
    {
        $email = $request->string('email')->toString();
        [, $url] = $invitations->send($email, $request->role(), $this->actor($request));

        return $this->sent($email, $url);
    }

    /** Erneut senden: neuer Link mit neuer Frist; der alte gilt nicht mehr. */
    public function resend(Request $request, Invitation $invitation, InvitationSender $invitations): RedirectResponse
    {
        if ($invitation->accepted_at !== null || User::query()->where('email', $invitation->email)->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Für diese Adresse gibt es schon ein Konto.')]);

            return back();
        }

        [, $url] = $invitations->send($invitation->email, $invitation->role, $this->actor($request), 'account.invitation_resent');

        return $this->sent($invitation->email, $url);
    }

    /** Zurückziehen: der Link gilt ab sofort nicht mehr. */
    public function destroy(Request $request, Invitation $invitation, AuditLog $audit): RedirectResponse
    {
        if ($invitation->isOpen()) {
            $invitation->forceFill(['expires_at' => now()])->save();
            $audit->record('account.invitation_withdrawn', $this->actor($request), $invitation->email);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Einladung an :email zurückgezogen.', ['email' => $invitation->email])]);

        return back();
    }

    private function sent(string $email, string $url): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Einladung an :email gesendet.', ['email' => $email])]);
        Inertia::flash('invitationLink', ['email' => $email, 'url' => $url]);

        return to_route('admin.invitations.index');
    }
}
