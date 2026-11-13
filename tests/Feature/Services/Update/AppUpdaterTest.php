<?php

namespace Tests\Feature\Services\Update;

use App\Models\AuditEntry;
use App\Models\User;
use App\Services\Audit\AuditPresenter;
use App\Services\Update\AppUpdater;
use App\Services\Update\UpdateFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UpdateInstallation;
use Tests\TestCase;

/**
 * Aktualisieren über die Oberfläche (B-09): Release prüfen, entpacken, austauschen, abschliessen — und bei jedem
 * Fehler die bisherige Version behalten. Läuft auf einer eigenen Installation im Temp-Ordner.
 */
class AppUpdaterTest extends TestCase
{
    use RefreshDatabase;
    use UpdateInstallation;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        $this->tearDownInstallation();

        parent::tearDown();
    }

    public function test_replaces_the_program_and_keeps_settings_app_database_and_storage(): void
    {
        $this->setUpInstallation();
        $this->publishRelease('1.5.0');
        $updater = app(AppUpdater::class);

        $updater->download($this->admin);
        $updater->extract();
        $wait = $updater->replace('durchlass', $this->admin);

        $this->assertSame(0, $wait);
        $this->assertStringContainsString('1.5.0', (string) file_get_contents($this->core.'/app/Models/Beispiel.php'));
        $this->assertStringContainsString('1.5.0', (string) file_get_contents($this->core.'/vendor/autoload.php'));
        $this->assertStringContainsString('1.5.0', (string) file_get_contents($this->core.'/public/index.php'));
        $this->assertFileExists($this->core.'/public/build/assets/app-1.5.0.js');
        $this->assertFileDoesNotExist($this->core.'/public/build/assets/app-1.4.0.js', 'gebaute Dateien der alten Version gehen');
        $this->assertFileDoesNotExist($this->core.'/app/Alt.php', 'was die neue Version nicht mehr hat, geht');
        $this->assertFileDoesNotExist($this->core.'/bootstrap/cache/packages.php', 'Zwischenstände der alten Version gehen');

        $this->assertSame("APP_KEY=base64:geheim\n", file_get_contents($this->core.'/.env'));
        $this->assertSame('App-Datenbank', file_get_contents($this->core.'/database/database.sqlite'));
        $this->assertSame('Daten der Installation', file_get_contents($this->core.'/storage/app/private/eigenes.txt'));
        $this->assertSame('Protokoll', file_get_contents($this->core.'/storage/logs/laravel.log'));

        $backup = $this->core.'/storage/app/private/aktualisierung/sicherung-1.4.0';
        $this->assertFileExists($backup.'/app/Alt.php');
        $this->assertStringContainsString('1.4.0', (string) file_get_contents($backup.'/vendor/autoload.php'));

        $this->assertTrue($this->app->maintenanceMode()->active(), 'während des Austauschs gesperrt');
        $this->assertSame('durchlass', $this->app->maintenanceMode()->data()['secret']);
        $this->assertSame('replaced', $updater->state()['step'] ?? null);

        // Der Abschluss läuft im Code der neuen Version.
        config(['phpdbadmin.version' => '1.5.0']);
        $state = $updater->finish($this->admin);

        $this->assertSame('finished', $state['step']);
        $this->assertFalse($this->app->maintenanceMode()->active());
        $this->assertFileDoesNotExist($this->core.'/storage/framework/maintenance.php');
        $this->assertDirectoryDoesNotExist($this->core.'/storage/app/private/aktualisierung/neu');
        $this->assertFileDoesNotExist($this->core.'/storage/app/private/aktualisierung/phpdbadmin-1.5.0.zip');
        $this->assertDirectoryExists($backup, 'die Sicherung bleibt');
        $this->assertSame('storage/app/private/aktualisierung/sicherung-1.4.0', $updater->summary()['backup'] ?? null);

        $entry = AuditEntry::query()->where('action', 'app.updated')->sole();
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame('1.4.0 → 1.5.0', app(AuditPresenter::class)->row($entry)['detail']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function webFolders(): array
    {
        return [
            'Programm neben dem Webordner' => ['outside'],
            'Programm im Webordner (.phpdbadmin)' => ['inside'],
        ];
    }

    #[DataProvider('webFolders')]
    public function test_web_folder_of_the_web_installer_gets_the_new_build_and_keeps_its_own_files(string $layout): void
    {
        $this->setUpInstallation($layout);
        $this->publishRelease('1.5.0');
        $updater = app(AppUpdater::class);

        $updater->download($this->admin);
        $updater->extract();
        $updater->replace('durchlass', $this->admin);

        $this->assertSame('{"version":"1.5.0"}', file_get_contents($this->webFolder.'/build/manifest.json'));
        $this->assertFileDoesNotExist($this->webFolder.'/build/assets/app-1.4.0.js');
        $this->assertSame('<svg>1.5.0</svg>', file_get_contents($this->webFolder.'/favicon.svg'));
        $this->assertSame('<?php // phpdbadmin-installer:index', file_get_contents($this->webFolder.'/index.php'), 'index.php des Installers bleibt');
        $this->assertSame('# BEGIN phpdbadmin-guard', file_get_contents($this->webFolder.'/.htaccess'));
        $this->assertSame('vom Betreiber', file_get_contents($this->webFolder.'/eigenes.html'));
        $this->assertStringContainsString('1.5.0', (string) file_get_contents($this->core.'/app/Models/Beispiel.php'));
        $this->assertSame("APP_KEY=base64:geheim\n", file_get_contents($this->core.'/.env'));

        config(['phpdbadmin.version' => '1.5.0']);
        $updater->finish($this->admin);

        $this->assertSame([], glob($this->webFolder.'/.phpdbadmin-sicherung-*') ?: [], 'im Webordner bleibt keine Sicherung liegen');
    }

    public function test_finish_waits_while_the_server_still_runs_the_previous_code(): void
    {
        $this->setUpInstallation();
        $this->publishRelease('1.5.0');
        $updater = app(AppUpdater::class);
        $updater->download($this->admin);
        $updater->extract();
        $updater->replace('durchlass', $this->admin);

        try {
            $updater->finish($this->admin);
            $this->fail('Mit dem Code von 1.4.0 schliesst die Aktualisierung nicht ab.');
        } catch (UpdateFailed $exception) {
            $this->assertTrue($exception->retry);
        }

        $this->assertSame('replaced', $updater->state()['step'] ?? null);
        $this->assertTrue($this->app->maintenanceMode()->active());
    }

    public function test_a_failed_migration_rolls_back_its_batch_and_brings_back_the_previous_version(): void
    {
        $this->setUpInstallation();
        $this->publishRelease('1.5.0');
        $updater = app(AppUpdater::class);
        $updater->download($this->admin);
        $updater->extract();
        $updater->replace('durchlass', $this->admin);

        $migrations = $this->installRoot.'/migrationen';
        $this->writeFiles($migrations, [
            '2099_01_01_000001_create_update_probe_table.php' => <<<'PHP'
                <?php
                use Illuminate\Database\Migrations\Migration;
                use Illuminate\Database\Schema\Blueprint;
                use Illuminate\Support\Facades\Schema;
                return new class extends Migration {
                    public function up(): void { Schema::create('update_probe', fn (Blueprint $table) => $table->id()); }
                    public function down(): void { Schema::drop('update_probe'); }
                };
                PHP,
            '2099_01_01_000002_fail.php' => <<<'PHP'
                <?php
                use Illuminate\Database\Migrations\Migration;
                return new class extends Migration {
                    public function up(): void { throw new RuntimeException('Spalte fehlt'); }
                };
                PHP,
        ]);
        $this->app->make('migrator')->path($migrations);
        config(['phpdbadmin.version' => '1.5.0']);
        // Wie in Produktion (AppServiceProvider): migrate:rollback ist gesperrt.
        DB::prohibitDestructiveCommands();

        try {
            $updater->finish($this->admin);
            $this->fail('Eine gescheiterte Migration schliesst die Aktualisierung nicht ab.');
        } catch (UpdateFailed $exception) {
            $this->assertStringContainsString('1.4.0', $exception->getMessage());
            $this->assertStringContainsString('Spalte fehlt', $exception->getMessage());
        } finally {
            // Die Sperre ist statisch — sie gälte sonst für jeden folgenden Test im Prozess.
            DB::prohibitDestructiveCommands(false);
        }

        $this->assertFalse(Schema::hasTable('update_probe'), 'die gelungene Migration des Stapels ist zurückgerollt');
        $this->assertStringContainsString('1.4.0', (string) file_get_contents($this->core.'/app/Models/Beispiel.php'));
        $this->assertFileExists($this->core.'/app/Alt.php');
        $this->assertFileExists($this->core.'/bootstrap/cache/packages.php');
        $this->assertSame("APP_KEY=base64:geheim\n", file_get_contents($this->core.'/.env'));
        $this->assertSame(['zustand.json'], array_values(array_diff(scandir($this->core.'/storage/app/private/aktualisierung') ?: [], ['.', '..'])));
        $this->assertFalse($this->app->maintenanceMode()->active());
        $this->assertSame('failed', $updater->state()['step'] ?? null);
        $this->assertSame(1, AuditEntry::query()->where('action', 'app.update_failed')->count());
    }

    public function test_a_failed_replacement_puts_every_entry_back(): void
    {
        $this->setUpInstallation();
        // Nach dem Tausch aller Ordner scheitert das Anlegen eines neuen Ordners in storage/app.
        $this->publishRelease('1.5.0', ['storage/app/public/.gitignore' => "*\n"]);
        $updater = app(AppUpdater::class);
        $updater->download($this->admin);
        $updater->extract();
        chmod($this->core.'/storage/app', 0555);

        try {
            $updater->replace('durchlass', $this->admin);
            $this->fail('Ein gescheiterter Tausch meldet sich.');
        } catch (UpdateFailed $exception) {
            $this->assertStringContainsString('1.4.0', $exception->getMessage());
        } finally {
            chmod($this->core.'/storage/app', 0755);
        }

        foreach (['app/Models/Beispiel.php', 'vendor/autoload.php', 'public/index.php', 'routes/web.php', 'config/phpdbadmin.php'] as $file) {
            $this->assertStringContainsString('1.4.0', (string) file_get_contents($this->core.'/'.$file), $file);
        }

        $this->assertFileExists($this->core.'/app/Alt.php');
        $this->assertFileExists($this->core.'/public/build/assets/app-1.4.0.js');
        $this->assertSame(['zustand.json'], array_values(array_diff(scandir($this->core.'/storage/app/private/aktualisierung') ?: [], ['.', '..'])), 'vom Versuch bleibt nur der Zustand');
        $this->assertFalse($this->app->maintenanceMode()->active());
        $this->assertSame('failed', $updater->state()['step'] ?? null);
        $this->assertSame(1, AuditEntry::query()->where('action', 'app.update_failed')->count());
    }

    public function test_refuses_an_archive_that_differs_from_the_signed_manifest(): void
    {
        $this->setUpInstallation();
        $this->publishRelease('1.5.0', tamper: true);

        $this->expectExceptionMessage('Prüfsumme');

        try {
            app(AppUpdater::class)->download($this->admin);
        } finally {
            $this->assertFileDoesNotExist($this->core.'/storage/app/private/aktualisierung/phpdbadmin-1.5.0.zip');
            $this->assertNull(app(AppUpdater::class)->state());
        }
    }

    public function test_refuses_an_archive_with_a_path_outside_its_folder(): void
    {
        $this->setUpInstallation();
        $files = $this->releaseFiles('1.5.0');
        $entries = array_combine(array_map(fn (string $path): string => 'phpdbadmin-1.5.0/'.$path, array_keys($files)), $files);
        $this->publishRelease('1.5.0', entries: [...$entries, 'phpdbadmin-1.5.0/../../ausserhalb.php' => '<?php // eingeschleust']);
        $updater = app(AppUpdater::class);
        $updater->download($this->admin);

        try {
            $updater->extract();
            $this->fail('Ein Pfad ausserhalb des Ordners wird nicht entpackt.');
        } catch (UpdateFailed $exception) {
            $this->assertStringContainsString('ausserhalb.php', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->core.'/storage/app/private/ausserhalb.php');
        $this->assertDirectoryDoesNotExist($this->core.'/storage/app/private/aktualisierung/neu');
        $this->assertSame('downloaded', $updater->state()['step'] ?? null);
    }

    public function test_refuses_an_archive_that_carries_another_version_than_its_manifest(): void
    {
        $this->setUpInstallation();
        $this->publishRelease('1.5.0', ['config/phpdbadmin.php' => "<?php\n\nreturn ['version' => '1.4.9'];\n"]);
        $updater = app(AppUpdater::class);
        $updater->download($this->admin);

        $this->expectExceptionMessage('1.4.9');

        $updater->extract();
    }

    public function test_never_installs_the_same_or_an_older_version(): void
    {
        $this->setUpInstallation();
        $this->publishRelease('1.4.0');

        $this->expectExceptionMessage('ist aktuell');

        app(AppUpdater::class)->download($this->admin);
    }

    public function test_git_checkout_and_requirements_of_the_release_block_the_update(): void
    {
        $this->setUpInstallation();
        mkdir($this->core.'/.git');
        $updater = app(AppUpdater::class);

        $this->assertSame(['git'], array_column($updater->blockers(), 'key'));
        $this->assertSame(['php', 'extensions'], array_column($updater->releaseBlockers([
            'current' => '1.4.0', 'latest' => '1.5.0', 'available' => true, 'published' => null, 'notesUrl' => null,
            'php' => '99.0.0', 'extensions' => ['json', 'gibt_es_nicht'], 'error' => null, 'checkedAt' => '2026-11-20T08:00:00Z',
        ]), 'key'));

        $this->publishRelease('1.5.0');
        $this->expectExceptionMessage('git pull');

        $updater->download($this->admin);
    }

    public function test_steps_run_only_in_order(): void
    {
        $this->setUpInstallation();
        $this->publishRelease('1.5.0');

        $this->expectExceptionMessage('passt nicht zum Stand');

        app(AppUpdater::class)->replace('durchlass', $this->admin);
    }
}
