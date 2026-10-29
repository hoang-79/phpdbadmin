<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\LocaleController;
use App\Http\Controllers\Account\OnboardingController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Connections\TestConnectionController;
use App\Http\Controllers\Workspace\ConnectionPasswordController;
use App\Http\Controllers\Workspace\ImportController;
use App\Http\Controllers\Workspace\ObjectController;
use App\Http\Controllers\Workspace\TransferController;
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
        Route::get('s/{schema}/o/{object}/structure', [ObjectController::class, 'structure'])
            ->where(['schema' => '[^/]+', 'object' => '[^/]+'])->name('workspace.object.structure');

        // Export und Import (E-01, E-02, E-06, B-07, Wireframe 11). Aufträge sieht nur das eigene Konto.
        Route::prefix('transfers')->name('workspace.transfers.')->group(function () {
            Route::get('/', [TransferController::class, 'index'])->name('index');
            Route::get('status', [TransferController::class, 'status'])->name('status');
            Route::post('export', [TransferController::class, 'export'])->name('export');
            Route::post('analyze', [TransferController::class, 'analyze'])->name('analyze');
            Route::post('import', [ImportController::class, 'store'])->name('import');
            Route::get('{transfer}', [ImportController::class, 'edit'])->whereUlid('transfer')->name('edit');
            Route::patch('{transfer}', [ImportController::class, 'update'])->whereUlid('transfer')->name('update');
            Route::post('{transfer}/run', [ImportController::class, 'run'])->whereUlid('transfer')->name('run');
            Route::post('{transfer}/cancel', [TransferController::class, 'cancel'])->whereUlid('transfer')->name('cancel');
            Route::get('{transfer}/download', [TransferController::class, 'download'])->whereUlid('transfer')->name('download');
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
