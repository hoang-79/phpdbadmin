<?php

namespace App\Http\Controllers;

use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suchquelle der Befehlspalette (O-02): die Verbindungen, die das Konto sieht — zum Wechseln. Objekte der offenen
 * Verbindung kennt die Seite schon aus dem Navigator.
 */
class PaletteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json([
            'connections' => DatabaseConnection::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->get(['id', 'name', 'group', 'driver', 'environment'])
                ->map(fn (DatabaseConnection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'group' => $connection->group,
                    'driverLabel' => $connection->driver->label(),
                    'environment' => $connection->environment->value,
                ])
                ->values(),
        ]);
    }
}
