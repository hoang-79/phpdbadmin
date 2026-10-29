<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\LocaleController;
use App\Http\Controllers\Account\OnboardingController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Connections\TestConnectionController;
use App\Http\Controllers\PaletteController;
use App\Http\Controllers\Workspace\ConnectionPasswordController;
use App\Http\Controllers\Workspace\ObjectController;
use App\Http\Controllers\Workspace\Schema;
use App\Http\Controllers\Workspace\Server;
use App\Http\Controllers\Workspace\SqlController;
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

        // SQL-Editor (Q-01 bis Q-04, Q-07, Q-09).
        Route::get('sql', [SqlController::class, 'show'])->name('workspace.sql');
        Route::post('sql/run', [SqlController::class, 'run'])->name('workspace.sql.run');
        Route::post('sql/cancel', [SqlController::class, 'cancel'])->name('workspace.sql.cancel');
        Route::get('sql/history', [SqlController::class, 'searchHistory'])->name('workspace.sql.history');
        Route::post('sql/saved', [SqlController::class, 'save'])->name('workspace.sql.saved.store');
        Route::delete('sql/saved/{query}', [SqlController::class, 'forget'])->name('workspace.sql.saved.destroy');
        Route::prefix('s/{schema}/o/{object}')->where(['schema' => '[^/]+', 'object' => '[^/]+'])->group(function () {
            Route::get('/', [ObjectController::class, 'data'])->name('workspace.object');
            Route::get('structure', [ObjectController::class, 'structure'])->name('workspace.object.structure');
            Route::get('rows', [ObjectController::class, 'rows'])->name('workspace.object.rows');
            Route::get('count', [ObjectController::class, 'count'])->name('workspace.object.count');
            Route::get('row', [ObjectController::class, 'row'])->name('workspace.object.row');
            Route::put('view', [ObjectController::class, 'saveView'])->name('workspace.object.view');

            // Tabelle ändern (S-03), umbenennen, kopieren, leeren, löschen (S-04).
            Route::get('alter', [Schema\TableAlterController::class, 'edit'])->name('workspace.object.alter');
            Route::post('alter/preview', [Schema\TableAlterController::class, 'preview'])->name('workspace.object.alter.preview');
            Route::post('alter', [Schema\TableAlterController::class, 'update'])->name('workspace.object.alter.apply');
            Route::post('actions/preview', [Schema\TableActionController::class, 'preview'])->name('workspace.object.actions.preview');
            Route::post('actions', [Schema\TableActionController::class, 'apply'])->name('workspace.object.actions.apply');
        });

        // Tabelle anlegen (S-02); Datenbank bzw. Schema anlegen und löschen (S-05). Nur mit «schreiben», ohne Schreibschutz.
        Route::prefix('s/{schema}')->where(['schema' => '[^/]+'])->group(function () {
            Route::get('new-table', [Schema\TableCreateController::class, 'create'])->name('workspace.table.create');
            Route::post('new-table/preview', [Schema\TableCreateController::class, 'preview'])->name('workspace.table.preview');
            Route::post('new-table', [Schema\TableCreateController::class, 'store'])->name('workspace.table.store');
        });
        Route::get('schemas/sqlite-folders', [Schema\SchemaController::class, 'folders'])->name('workspace.schemas.folders');
        Route::post('schemas/preview', [Schema\SchemaController::class, 'preview'])->name('workspace.schemas.preview');
        Route::post('schemas', [Schema\SchemaController::class, 'apply'])->name('workspace.schemas.apply');

        // Server-Verwaltung (W-01 bis W-05): ansehen mit Zugang, ändern nur mit «verwalten» und ohne Schreibschutz.
        Route::get('server', Server\OverviewController::class)->name('workspace.server');
        Route::get('server/processes', [Server\ProcessController::class, 'index'])->name('workspace.server.processes');
        Route::post('server/processes/{process}/end', [Server\ProcessController::class, 'end'])->where('process', '[1-9][0-9]{0,17}')->name('workspace.server.processes.end');
        Route::get('server/variables', Server\VariableController::class)->name('workspace.server.variables');
        Route::get('server/accounts', [Server\AccountController::class, 'index'])->name('workspace.server.accounts');
        Route::post('server/accounts/preview', [Server\AccountController::class, 'preview'])->name('workspace.server.accounts.preview');
        Route::post('server/accounts', [Server\AccountController::class, 'apply'])->name('workspace.server.accounts.apply');
        Route::get('server/maintenance', [Server\MaintenanceController::class, 'index'])->name('workspace.server.maintenance');
        Route::post('server/maintenance/preview', [Server\MaintenanceController::class, 'preview'])->name('workspace.server.maintenance.preview');
        Route::post('server/maintenance', [Server\MaintenanceController::class, 'run'])->name('workspace.server.maintenance.run');
    });

    // Befehlspalette ⌘K (O-02): Suchquelle für das Wechseln der Verbindung.
    Route::get('palette', PaletteController::class)->name('palette');

    // Kein Profil (A-05): das Konto zeigt Sicherheit und Darstellung.
    Route::get('account', [AccountController::class, 'show'])->name('account');
    Route::put('account/locale', LocaleController::class)->name('account.locale');
});
