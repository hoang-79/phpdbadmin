<?php

use App\Enums\ObjectKind;
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
use App\Http\Controllers\PaletteController;
use App\Http\Controllers\Workspace\ConnectionPasswordController;
use App\Http\Controllers\Workspace\DefinitionController;
use App\Http\Controllers\Workspace\DiagramController;
use App\Http\Controllers\Workspace\ImportController;
use App\Http\Controllers\Workspace\ObjectController;
use App\Http\Controllers\Workspace\RowChangeController;
use App\Http\Controllers\Workspace\Server;
use App\Http\Controllers\Workspace\SqlController;
use App\Http\Controllers\Workspace\TransferController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\Workspace\WriteUnlockController;
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

        // Schreibschutz auf Zeit aufheben (V-11).
        Route::post('unlock', [WriteUnlockController::class, 'store'])->name('workspace.unlock');
        Route::delete('unlock', [WriteUnlockController::class, 'destroy'])->name('workspace.unlock.destroy');

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
            Route::get('copy', [RowChangeController::class, 'copy'])->name('workspace.object.copy');
            Route::post('changes/preview', [RowChangeController::class, 'preview'])->name('workspace.object.changes.preview');
            Route::post('changes', [RowChangeController::class, 'apply'])->name('workspace.object.changes');
            Route::post('changes/{audit}/undo', [RowChangeController::class, 'undo'])->whereNumber('audit')->name('workspace.object.changes.undo');
        });

        // Objekte neben den Tabellen (S-06): Sichten, Routinen, Trigger, Ereignisse, Sequenzen, Typen — ansehen mit
        // Zugang, anlegen, ändern und löschen mit «schreiben», Vorschau zuerst.
        Route::prefix('s/{schema}/x/{kind}')->where(['schema' => '[^/]+', 'kind' => ObjectKind::pattern()])->group(function () {
            Route::get('/', [DefinitionController::class, 'create'])->name('workspace.definition.create');
            Route::post('preview', [DefinitionController::class, 'preview'])->name('workspace.definition.preview');
            Route::post('/', [DefinitionController::class, 'apply'])->name('workspace.definition.apply');
            Route::get('{name}', [DefinitionController::class, 'detail'])->where('name', '[^/]+')->name('workspace.definition');
        });

        // Beziehungsdiagramm eines Schemas (S-07): ansehen und als SVG herunterladen.
        Route::get('s/{schema}/diagram', [DiagramController::class, 'diagram'])->where('schema', '[^/]+')->name('workspace.diagram');
        Route::post('s/{schema}/diagram/svg', [DiagramController::class, 'svg'])->where('schema', '[^/]+')->name('workspace.diagram.svg');

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

    // Befehlspalette ⌘K (O-02): Suchquelle für das Wechseln der Verbindung.
    Route::get('palette', PaletteController::class)->name('palette');
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
