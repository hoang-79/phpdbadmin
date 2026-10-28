<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\LocaleController;
use App\Http\Controllers\Account\OnboardingController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Connections\TestConnectionController;
use App\Http\Controllers\Workspace\ConnectionPasswordController;
use App\Http\Controllers\Workspace\ObjectController;
use App\Http\Controllers\Workspace\WorkspaceController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    // Kein Dashboard (A-04): die Startseite ist die Verbindungsübersicht.
    Route::get('/', [ConnectionController::class, 'index'])->name('home');

    // Zielverbindungen (V-01 bis V-05). {connection} löst nur sichtbare Verbindungen auf (AppServiceProvider).
    Route::post('connections/test', TestConnectionController::class)->middleware('throttle:connection-test')->name('connections.test');
    Route::resource('connections', ConnectionController::class)->except(['index', 'show']);

    // Nach der ersten Anmeldung ohne Passkey: gleich einen anlegen.
    Route::get('welcome', [OnboardingController::class, 'show'])->name('onboarding');

    // Arbeitsbildschirm (Wireframe 05): Verbindung → Schema (bei MySQL/MariaDB eine Datenbank) → Objekt.
    Route::prefix('c/{connection}')->group(function () {
        Route::get('/', [WorkspaceController::class, 'show'])->name('workspace');
        Route::get('password', [ConnectionPasswordController::class, 'show'])->name('workspace.password');
        Route::post('password', [ConnectionPasswordController::class, 'store'])->middleware('throttle:connection-test')->name('workspace.password.store');
        Route::get('s/{schema}', [WorkspaceController::class, 'schema'])->where('schema', '[^/]+')->name('workspace.schema');
        Route::prefix('s/{schema}/o/{object}')->where(['schema' => '[^/]+', 'object' => '[^/]+'])->group(function () {
            Route::get('/', [ObjectController::class, 'data'])->name('workspace.object');
            Route::get('structure', [ObjectController::class, 'structure'])->name('workspace.object.structure');
            Route::get('rows', [ObjectController::class, 'rows'])->name('workspace.object.rows');
            Route::get('count', [ObjectController::class, 'count'])->name('workspace.object.count');
            Route::get('row', [ObjectController::class, 'row'])->name('workspace.object.row');
            Route::put('view', [ObjectController::class, 'saveView'])->name('workspace.object.view');
        });
    });

    // Kein Profil (A-05): das Konto zeigt Sicherheit und Darstellung.
    Route::get('account', [AccountController::class, 'show'])->name('account');
    Route::put('account/locale', LocaleController::class)->name('account.locale');
});

// Feasibility-Proben F-3 und F-4, nur lokal und nur angemeldet — bis Raster (TB 01) und SQL-Editor (TB 05) sie ersetzen.
if (app()->environment('local')) {
    Route::middleware('auth')->group(__DIR__.'/probe.php');
}
