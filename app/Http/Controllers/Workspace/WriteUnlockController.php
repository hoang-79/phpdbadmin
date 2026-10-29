<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\WriteUnlock;
use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Schreibschutz auf Zeit aufheben und wieder sperren (V-11): nur mit Recht «schreiben», mit Pflichtgrund, Grund und
 * Ende im Protokoll.
 */
class WriteUnlockController extends Controller
{
    public function __construct(private readonly WriteUnlock $unlock, private readonly AuditLog $audit) {}

    public function store(Request $request, DatabaseConnection $connection): RedirectResponse
    {
        Gate::authorize('write', $connection);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], attributes: ['reason' => __('Grund')])['reason'];

        if (! $connection->read_only) {
            return back();
        }

        $until = $this->unlock->unlock($connection, $reason);
        $this->audit->record('connection.unlock', $this->user($request), context: ['reason' => $reason, 'until' => $until->toIso8601String()], connection: $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Schreiben erlaubt bis :time.', ['time' => $until->setTimezone($this->user($request)->timezone)->format('H:i')])]);

        return back();
    }

    public function destroy(Request $request, DatabaseConnection $connection): RedirectResponse
    {
        if ($this->unlock->until($connection) !== null) {
            $this->unlock->lock($connection);
            $this->audit->record('connection.lock', $this->user($request), connection: $connection);
        }

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
