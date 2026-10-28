<?php

namespace App\Http\Controllers\Connections;

use App\Connections\ConnectionSettings;
use App\Connections\ConnectionTester;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\TestConnectionRequest;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * «Verbindung testen» vor dem Speichern (V-02). Antwortet als JSON für useHttp; das Passwort geht nie zurück.
 */
class TestConnectionController extends Controller
{
    public function __invoke(TestConnectionRequest $request, ConnectionTester $tester): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $settings = ConnectionSettings::fromInput($request->validated());
        $saved = $this->savedConnection($request, $user);

        // Beim Bearbeiten mit leerem Passwortfeld: das gespeicherte verwenden.
        if ($saved !== null && $settings->driver->isServer() && ($settings->password === null)) {
            $settings = $settings->withPassword($saved->password);
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

        return $connection !== null && $connection->isManageableBy($user) ? $connection : null;
    }
}
