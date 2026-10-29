<?php

namespace App\Http\Controllers\Connections;

use App\Connections\ConnectionSettings;
use App\Connections\ConnectionTester;
use App\Connections\HerdDiscovery;
use App\Connections\SshSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\TestConnectionRequest;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * «Verbindung testen» vor dem Speichern (V-02). Antwortet als JSON für useHttp; Passwort, Schlüssel und Passphrase
 * gehen nie zurück — nur der Fingerabdruck des SSH-Servers (V-08).
 */
class TestConnectionController extends Controller
{
    public function __invoke(TestConnectionRequest $request, ConnectionTester $tester, HerdDiscovery $herd): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $settings = ConnectionSettings::fromInput($request->validated());
        $saved = $this->savedConnection($request, $user);

        // Beim Bearbeiten mit leerem Passwortfeld: das gespeicherte verwenden.
        if ($saved !== null && $settings->driver->isServer() && ($settings->password === null)) {
            $settings = $settings->withPassword($saved->password);
        }

        // Vorschlag aus Herd übernommen (V-07): Passwort aus der Quelle — nur Administratoren, nur passender Host.
        if ($saved === null && $settings->driver->isServer() && $settings->password === null && $request->filled('herd_source') && $user->isAdmin() && $herd->enabled()) {
            $settings = $settings->withPassword($herd->passwordFor($request->string('herd_source')->toString(), $settings->host, $settings->port, $settings->username));
        }

        // Ebenso Schlüssel und Passphrase des SSH-Tunnels (V-08).
        $ssh = $settings->ssh;
        if ($saved !== null && $ssh !== null && $ssh->privateKey === null && $ssh->keyPath === null) {
            $settings = $settings->withSsh(new SshSettings($ssh->host, $ssh->port, $ssh->username, $saved->ssh_key, $saved->ssh_key_path, $ssh->passphrase ?? $saved->ssh_passphrase));
        } elseif ($saved !== null && $ssh !== null && $ssh->passphrase === null && $ssh->keyPath !== null && $ssh->keyPath === $saved->ssh_key_path) {
            $settings = $settings->withSsh($ssh->withKey(null, $saved->ssh_passphrase));
        }

        $result = $tester->test($settings);

        if ($result->ok && $saved !== null) {
            $saved->forceFill(['server_version' => $result->version, 'account_warnings' => $result->warnings, 'last_tested_at' => now()])->saveQuietly();
        }

        return response()->json($result->toArray());
    }

    private function savedConnection(TestConnectionRequest $request, User $user): ?DatabaseConnection
    {
        if (! $request->filled('connection')) {
            return null;
        }

        $connection = DatabaseConnection::query()->visibleTo($user)->find($request->string('connection')->toString());

        // Das gespeicherte Passwort nur mit dem Recht «verwalten» (wie beim Bearbeiten).
        return $connection !== null && $user->can('manage', $connection) ? $connection : null;
    }
}
