<?php

namespace App\Http\Controllers\Connections;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Startseite = Verbindungsübersicht (A-04). Kein Dashboard.
 */
class ConnectionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('connections/Index', [
            'connections' => [],
        ]);
    }
}
