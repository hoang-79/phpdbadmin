<?php

namespace App\Http\Controllers\Workspace\Server;

use App\Models\DatabaseConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Variablen und Status (W-03), nur lesen; gesucht wird in der Oberfläche.
 */
class VariableController extends ServerController
{
    public function __invoke(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->page($request, $connection, 'variables', fn (?string $database): array => $this->inspector->settings($connection, $database));
    }
}
