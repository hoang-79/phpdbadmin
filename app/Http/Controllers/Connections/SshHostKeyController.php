<?php

namespace App\Http\Controllers\Connections;

use App\Connections\KnownHosts;
use App\Connections\SshSettings;
use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Gespeicherten Host-Schlüssel eines SSH-Servers vergessen (V-08) — nach einem neu aufgesetzten Server. Nur mit dem
 * Recht «verwalten» (und nur Administratoren, wenn andere Konten denselben Server nutzen), im Protokoll; beim nächsten
 * Verbinden nimmt phpdbadmin den neuen Schlüssel an.
 */
class SshHostKeyController extends Controller
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Request $request, DatabaseConnection $connection, KnownHosts $knownHosts): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        Gate::authorize('manage', $connection);
        abort_unless($connection->usesSsh(), 404);

        // Die known_hosts gilt für die ganze Installation: Vergessen träfe auch die Verbindungen anderer zu diesem
        // SSH-Server, die danach jeden angebotenen Schlüssel annähmen (N-02). Das darf nur, wer alle verwaltet.
        $port = $connection->ssh_port ?? SshSettings::DEFAULT_PORT;
        $affectsOthers = DatabaseConnection::query()
            ->whereKeyNot($connection->getKey())
            ->where('ssh_host', $connection->ssh_host)
            ->get()
            ->contains(fn (DatabaseConnection $other): bool => ($other->ssh_port ?? SshSettings::DEFAULT_PORT) === $port
                && Gate::forUser($user)->denies('manage', $other));
        abort_if($affectsOthers && ! $user->isAdmin(), 403, __('Diesen SSH-Server nutzen auch Verbindungen anderer Konten — den Host-Schlüssel vergisst eine Administratorin oder ein Administrator.'));

        $knownHosts->forget((string) $connection->ssh_host, $connection->ssh_port ?? SshSettings::DEFAULT_PORT);
        $this->audit->record('connection.ssh_host_key_forgotten', $user, KnownHosts::name((string) $connection->ssh_host, $connection->ssh_port ?? SshSettings::DEFAULT_PORT), connection: $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Host-Schlüssel vergessen. Beim nächsten Verbinden nimmt phpdbadmin den neuen an.')]);

        return back();
    }
}
