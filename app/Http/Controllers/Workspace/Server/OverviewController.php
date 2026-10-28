<?php

namespace App\Http\Controllers\Workspace\Server;

use App\Models\DatabaseConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Server-Übersicht (W-01): Version, Laufzeit, Zeichensätze, Grösse je Datenbank bzw. Schema; bei SQLite die Datei.
 */
class OverviewController extends ServerController
{
    public function __invoke(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->page($request, $connection, 'overview', fn (?string $database): array => [
            'overview' => $this->inspector->overview($connection, $database),
        ]);
    }
}
