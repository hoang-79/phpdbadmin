<?php

namespace App\Http\Controllers\Connections;

use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stern in der Übersicht (V-06): Favorit je Konto setzen oder entfernen. Ein persönlicher Merker — er ändert nichts
 * an der Verbindung und steht nicht im Protokoll. Fremde Verbindungen sind 404 (Routenbindung).
 */
class FavoriteController extends Controller
{
    public function __invoke(Request $request, DatabaseConnection $connection): RedirectResponse
    {
        $request->validate(['favorite' => ['required', 'boolean']], attributes: ['favorite' => __('Favorit')]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $connection->markFavorite($user, $request->boolean('favorite'));

        return back();
    }
}
