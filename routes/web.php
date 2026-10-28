<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Connections\TestConnectionController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    // Kein Dashboard (A-04): die Startseite ist die Verbindungsübersicht.
    Route::get('/', [ConnectionController::class, 'index'])->name('home');

    // Zielverbindungen (V-01 bis V-05). {connection} löst nur sichtbare Verbindungen auf (AppServiceProvider).
    Route::post('connections/test', TestConnectionController::class)->middleware('throttle:connection-test')->name('connections.test');
    Route::resource('connections', ConnectionController::class)->except(['index', 'show']);

    // Kein Profil (A-05): das Konto zeigt Sicherheit und Darstellung.
    Route::get('account', [AccountController::class, 'show'])->name('account');
});

// Feasibility-Proben F-3 und F-4, nur lokal (dokumentationen/02-feasibility).
if (app()->environment('local')) {
    require __DIR__.'/probe.php';
}
