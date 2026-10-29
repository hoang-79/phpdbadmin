<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\LocaleController;
use App\Http\Controllers\Account\OnboardingController;
use App\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\InvitationController as AdminInvitationController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Connections\FavoriteController;
use App\Http\Controllers\Connections\SharingController;
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

    // Teilen (V-09): nur Administratoren — die Policy antwortet 403, eine fremde persönliche Verbindung bleibt 404.
    Route::get('connections/{connection}/sharing', [SharingController::class, 'show'])->name('connections.sharing.show');
    Route::put('connections/{connection}/sharing', [SharingController::class, 'update'])->name('connections.sharing.update');

    // Favorit je Konto (V-06).
    Route::put('connections/{connection}/favorite', FavoriteController::class)->name('connections.favorite');

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
    });

    // Verwaltung (Wireframe 14): Konten, Einladungen, Rechte, Protokoll — nur Administratoren (403 für alle anderen).
    Route::middleware('can:administrate')->prefix('admin')->name('admin.')->group(function () {
        Route::get('accounts', [AdminAccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/{account}', [AdminAccountController::class, 'show'])->name('accounts.show');
        Route::patch('accounts/{account}', [AdminAccountController::class, 'update'])->name('accounts.update');
        Route::put('accounts/{account}/role', [AdminAccountController::class, 'updateRole'])->name('accounts.role');
        Route::put('accounts/{account}/status', [AdminAccountController::class, 'updateStatus'])->name('accounts.status');
        Route::put('accounts/{account}/permissions', [AdminAccountController::class, 'updatePermissions'])->name('accounts.permissions');
        Route::delete('accounts/{account}', [AdminAccountController::class, 'destroy'])->name('accounts.destroy');

        Route::get('invitations', [AdminInvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [AdminInvitationController::class, 'store'])->middleware('throttle:invitations')->name('invitations.store');
        Route::post('invitations/{invitation}/resend', [AdminInvitationController::class, 'resend'])->middleware('throttle:invitations')->name('invitations.resend');
        Route::delete('invitations/{invitation}', [AdminInvitationController::class, 'destroy'])->name('invitations.destroy');

        // Protokoll (B-04): Filter, Seiten, Export als CSV.
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit/export', [AuditController::class, 'export'])->name('audit.export');
    });

    // Kein Profil (A-05): das Konto zeigt Sicherheit und Darstellung.
    Route::get('account', [AccountController::class, 'show'])->name('account');
    Route::put('account/locale', LocaleController::class)->name('account.locale');
});

// Feasibility-Proben F-3 und F-4, nur lokal und nur angemeldet — bis Raster (TB 01) und SQL-Editor (TB 05) sie ersetzen.
if (app()->environment('local')) {
    Route::middleware('auth')->group(__DIR__.'/probe.php');
}
