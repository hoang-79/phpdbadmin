<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\ConnectionSettings;
use App\Connections\ConnectionTester;
use App\Connections\TargetConnections;
use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passwort für Verbindungen, die keines speichern (V-03 «in jeder Sitzung fragen»): einmal je Sitzung eingeben,
 * geprüft, dann verschlüsselt in der Sitzung gehalten — nie in der App-Datenbank.
 */
class ConnectionPasswordController extends Controller
{
    public function show(DatabaseConnection $connection): Response
    {
        return Inertia::render('connect/Password', [
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'username' => $connection->username,
                'location' => $connection->host.':'.($connection->port ?? $connection->driver->defaultPort()),
                'environment' => $connection->environment->value,
            ],
        ]);
    }

    public function store(Request $request, DatabaseConnection $connection, ConnectionTester $tester, TargetConnections $targets): RedirectResponse
    {
        $password = (string) $request->validate(['password' => ['required', 'string', 'max:1024']])['password'];

        $result = $tester->test(ConnectionSettings::fromConnection($connection, $password), connectTimeout: 3);

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'password' => ($result->problem?->title() ?? 'Verbindung gescheitert').' — '.($result->problem?->kind->explanation() ?? ''),
            ]);
        }

        $targets->rememberPassword($connection, $password);

        return redirect()->intended(route('workspace', $connection));
    }
}
