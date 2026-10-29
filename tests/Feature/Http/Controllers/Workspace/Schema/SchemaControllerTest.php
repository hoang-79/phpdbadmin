<?php

namespace Tests\Feature\Http\Controllers\Workspace\Schema;

use App\Connections\ConnectionFactory;
use App\Connections\HostPolicy;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Datenbank bzw. Schema anlegen und löschen (S-05) je Treiber: MySQL und MariaDB eine Datenbank (nur über eine
 * Verbindung ohne festen Datenbanknamen), PostgreSQL ein Schema, SQLite eine neue Datei in einem erlaubten Ordner.
 * Löschen nur mit dem eingetippten Namen. Bei PostgreSQL mit «alle Datenbanken» auch eine ganze Datenbank — über die
 * Wartungsdatenbank, nie die Vorlagen, die Wartungsdatenbank selbst oder die App-Datenbank von phpdbadmin.
 * Datenbanken heissen «phpdbadmin_s05_…» und werden im Test wieder entfernt.
 */
#[Group('target-databases')]
class SchemaControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/schemas-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    #[DataProvider('serverDrivers')]
    public function test_creates_and_drops_a_database_or_schema_only_with_its_name(DatabaseDriver $driver): void
    {
        $isPostgres = $driver === DatabaseDriver::Postgres;
        // PostgreSQL: Schema in der Test-Datenbank; MySQL/MariaDB: Verbindung ohne festen Datenbanknamen.
        $settings = $isPostgres ? $this->scratchSettings($driver, $this->folder) : $this->serverSettings($driver);
        $connection = $this->savedConnection($settings, $this->user);
        $name = 'phpdbadmin_s05_'.getmypid();
        $root = app(ConnectionFactory::class)->make($settings);

        try {
            $this->actingAs($this->user)->from(route('workspace', $connection))
                ->post(route('workspace.schemas.apply', $connection), ['operation' => 'create', 'name' => $name])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('workspace.schema', [$connection, $name]));
            $this->assertContains($name, $driver->dialect()->schemas($root));

            $this->post(route('workspace.schemas.apply', $connection), ['operation' => 'drop', 'name' => $name])
                ->assertSessionHasErrors(['confirm_name' => "Geben Sie zur Bestätigung den Namen «{$name}» ein."]);
            $this->assertContains($name, $driver->dialect()->schemas($root));

            $this->post(route('workspace.schemas.apply', $connection), ['operation' => 'drop', 'name' => $name, 'confirm_name' => $name])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('workspace', $connection));
            $this->assertNotContains($name, $driver->dialect()->schemas($root));
            $this->assertSame(['database.create', 'database.drop'], AuditEntry::query()->orderBy('id')->pluck('action')->all());
        } finally {
            $root->statement(($isPostgres ? 'drop schema if exists ' : 'drop database if exists ').$name.($isPostgres ? ' cascade' : ''));
        }
    }

    public function test_drops_a_whole_postgres_database_only_with_its_name_even_while_it_is_open(): void
    {
        $connection = $this->savedConnection($this->serverSettings(DatabaseDriver::Postgres, ['database' => null]), $this->user);
        $root = app(ConnectionFactory::class)->make($this->serverSettings(DatabaseDriver::Postgres));
        $name = 'phpdbadmin_s05_db_'.getmypid();
        $this->createPostgresDatabase($root, $name, withContent: true);

        try {
            $this->actingAs($this->user)->postJson(route('workspace.schemas.preview', $connection), ['operation' => 'drop-database', 'name' => $name, 'database' => $name])
                ->assertOk()
                ->assertJsonPath('mode', 'statements')
                ->assertJsonPath('before', [])
                ->assertJsonPath('statements.0.sql', 'DROP DATABASE "'.$name.'"')
                ->assertJsonPath('confirmName', $name)
                ->assertJsonPath('losses', ['Gelöscht wird die ganze Datenbank mit allem darin — Schemas: 2, Tabellen und Sichten: 1.']);
            $this->assertContains($name, $this->postgresDatabases($root), 'die Vorschau führt nichts aus');

            $this->post(route('workspace.schemas.apply', $connection), ['operation' => 'drop-database', 'name' => $name, 'database' => $name])
                ->assertSessionHasErrors(['confirm_name' => "Geben Sie zur Bestätigung den Namen «{$name}» ein."]);
            $this->post(route('workspace.schemas.apply', $connection), ['operation' => 'drop-database', 'name' => $name, 'database' => $name, 'confirm_name' => 'falsch'])
                ->assertSessionHasErrors('confirm_name');
            $this->assertContains($name, $this->postgresDatabases($root));
            $this->assertSame(0, AuditEntry::query()->count());

            // Die gelöschte Datenbank war gerade offen (database): trotzdem über die Wartungsdatenbank, danach zur Vorgabe.
            $this->post(route('workspace.schemas.apply', $connection), ['operation' => 'drop-database', 'name' => $name, 'database' => $name, 'confirm_name' => $name])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('workspace', $connection))
                ->assertInertiaFlash('toast.message', "Datenbank {$name} gelöscht.");
            $this->assertNotContains($name, $this->postgresDatabases($root));

            $entry = AuditEntry::query()->sole();
            $this->assertSame(['database.drop', $name, 'DROP DATABASE "'.$name.'"'], [$entry->action, $entry->target, $entry->sql]);
            $this->assertSame(['database', 2, 1, 'ok'], [$entry->context['scope'], $entry->context['schemas'], $entry->context['tables'], $entry->context['outcome']]);
        } finally {
            $root->statement('drop database if exists "'.$name.'"');
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedDatabases(): array
    {
        return [
            'Vorlage' => ['template1', 'Diese Datenbank braucht PostgreSQL selbst — sie lässt sich hier nicht löschen.'],
            'Wartungsdatenbank' => ['postgres', 'Über die Datenbank «postgres» verbindet phpdbadmin mit dem Server — sie lässt sich hier nicht löschen.'],
            'gibt es nicht' => ['phpdbadmin_s05_gibt_es_nicht', 'Diese Datenbank gibt es auf dem Server nicht.'],
        ];
    }

    #[DataProvider('protectedDatabases')]
    public function test_never_drops_templates_the_maintenance_database_or_unknown_names(string $name, string $message): void
    {
        $connection = $this->savedConnection($this->serverSettings(DatabaseDriver::Postgres, ['database' => null]), $this->user);

        $this->actingAs($this->user)->postJson(route('workspace.schemas.apply', $connection), ['operation' => 'drop-database', 'name' => $name, 'confirm_name' => $name])
            ->assertJsonValidationErrors(['name' => $message]);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_never_drops_the_app_database_of_phpdbadmin(): void
    {
        $root = app(ConnectionFactory::class)->make($this->serverSettings(DatabaseDriver::Postgres));
        $name = 'phpdbadmin_s05_app_'.getmypid();
        $this->createPostgresDatabase($root, $name);
        $this->pretendAppDatabase(DatabaseDriver::Postgres, $name);
        $connection = $this->savedConnection($this->serverSettings(DatabaseDriver::Postgres, ['database' => null]), $this->user);

        try {
            $this->actingAs($this->user)->postJson(route('workspace.schemas.apply', $connection), ['operation' => 'drop-database', 'name' => $name, 'confirm_name' => $name])
                ->assertJsonValidationErrors(['name' => 'Das ist die eigene Datenbank von phpdbadmin — sie lässt sich hier nicht löschen.']);
            $this->assertContains($name, $this->postgresDatabases($root));
        } finally {
            $root->statement('drop database if exists "'.$name.'"');
        }
    }

    public function test_never_drops_the_app_database_on_mariadb_either(): void
    {
        $settings = $this->serverSettings(DatabaseDriver::MariaDb);
        $root = app(ConnectionFactory::class)->make($settings);
        $name = 'phpdbadmin_s05_app_'.getmypid();
        $root->statement('create database if not exists '.$name);
        $this->pretendAppDatabase(DatabaseDriver::MariaDb, $name);
        $connection = $this->savedConnection($settings, $this->user);

        try {
            $this->actingAs($this->user)->postJson(route('workspace.schemas.apply', $connection), ['operation' => 'drop', 'name' => $name, 'confirm_name' => $name])
                ->assertJsonValidationErrors(['name' => 'Das ist die eigene Datenbank von phpdbadmin — sie lässt sich hier nicht löschen.']);
            $this->assertContains($name, DatabaseDriver::MariaDb->dialect()->schemas($root));
        } finally {
            $root->statement('drop database if exists '.$name);
        }
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function withoutAllDatabases(): array
    {
        return [
            'PostgreSQL mit fester Datenbank' => [DatabaseDriver::Postgres],
            'MariaDB' => [DatabaseDriver::MariaDb],
        ];
    }

    #[DataProvider('withoutAllDatabases')]
    public function test_whole_databases_are_dropped_only_with_all_databases(DatabaseDriver $driver): void
    {
        $connection = $this->savedConnection($this->serverSettings($driver), $this->user);

        $this->actingAs($this->user)->postJson(route('workspace.schemas.preview', $connection), ['operation' => 'drop-database', 'name' => 'phpdbadmin_s05_nie'])
            ->assertJsonValidationErrors(['name' => 'Ganze Datenbanken löscht phpdbadmin hier nur bei PostgreSQL-Verbindungen mit «alle Datenbanken».']);
    }

    public function test_dropping_a_database_needs_the_write_permission_and_no_write_protection(): void
    {
        $root = app(ConnectionFactory::class)->make($this->serverSettings(DatabaseDriver::Postgres));
        $name = 'phpdbadmin_s05_recht_'.getmypid();
        $this->createPostgresDatabase($root, $name);
        $settings = $this->serverSettings(DatabaseDriver::Postgres, ['database' => null]);
        $payload = ['operation' => 'drop-database', 'name' => $name, 'confirm_name' => $name];

        try {
            $protected = $this->savedConnection($settings, $this->user, ['read_only' => true]);
            $this->actingAs($this->user)->postJson(route('workspace.schemas.apply', $protected), $payload)
                ->assertForbidden()
                ->assertJsonPath('message', 'Diese Verbindung ist schreibgeschützt — Strukturänderungen sind gesperrt.');

            $shared = $this->savedConnection($settings, User::factory()->create(), ['shared' => true]);
            $shared->members()->attach($this->user, ['permission' => ConnectionPermission::Read->value]);
            $this->actingAs($this->user)->postJson(route('workspace.schemas.apply', $shared), $payload)
                ->assertForbidden()
                ->assertJsonPath('message', 'Dafür braucht es das Recht «schreiben» an dieser Verbindung.');

            $this->assertContains($name, $this->postgresDatabases($root));
            $this->assertSame(0, AuditEntry::query()->count());
        } finally {
            $root->statement('drop database if exists "'.$name.'"');
        }
    }

    public function test_drop_preview_names_what_is_lost(): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::Postgres, $this->folder), $this->user);

        $this->actingAs($this->user)->postJson(route('workspace.schemas.preview', $connection), ['operation' => 'drop', 'name' => 'public'])
            ->assertOk()
            ->assertJsonPath('statements.0.sql', 'DROP SCHEMA "public" CASCADE')
            ->assertJsonPath('confirmName', 'public')
            ->assertJsonPath('losses', ['Gelöscht wird alles darin: 0 Tabellen und Sichten mit allen Daten.']);
    }

    public function test_connection_with_a_fixed_database_cannot_create_databases(): void
    {
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder), $this->user);

        $this->actingAs($this->user)->postJson(route('workspace.schemas.preview', $connection), ['operation' => 'create', 'name' => 'phpdbadmin_s05_nie'])
            ->assertJsonValidationErrors('name');
    }

    public function test_sqlite_creates_a_new_file_in_an_allowed_folder(): void
    {
        $connection = $this->sqliteConnection();
        $folder = (string) realpath($this->folder);

        $this->actingAs($this->user)->getJson(route('workspace.schemas.folders', $connection))
            ->assertOk()
            ->assertExactJson(['folders' => [$folder]]);
        $this->from(route('workspace.schema', [$connection, 'main']))
            ->post(route('workspace.schemas.apply', $connection), ['operation' => 'create', 'folder' => $folder, 'file' => 'neu.sqlite'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.schema', [$connection, 'main']))
            ->assertInertiaFlash('sqliteFile', $folder.'/neu.sqlite');

        $this->assertSame('0', (string) (new PDO('sqlite:'.$folder.'/neu.sqlite'))->query('select count(*) from sqlite_master')->fetchColumn());
        $this->assertStringStartsWith("SQLite format 3\0", (string) file_get_contents($folder.'/neu.sqlite'));
        $entry = AuditEntry::query()->sole();
        $this->assertSame(['database.create', 'neu.sqlite', null], [$entry->action, $entry->target, $entry->sql]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidFiles(): array
    {
        return [
            'Ordner ausserhalb' => ['/tmp', 'neu.sqlite', 'folder'],
            'Pfad im Dateinamen' => ['', '../neu.sqlite', 'file'],
            'falsche Endung' => ['', 'neu.php', 'file'],
            'gibt es schon' => ['', 'bestand.sqlite', 'file'],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_sqlite_refuses_files_outside_the_allowed_folders(string $folder, string $file, string $field): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->postJson(route('workspace.schemas.apply', $connection), [
            'operation' => 'create',
            'folder' => $folder === '' ? (string) realpath($this->folder) : $folder,
            'file' => $file,
        ])->assertJsonValidationErrors($field);

        $this->assertFileDoesNotExist(dirname($this->folder).'/neu.sqlite');
        $this->assertSame(['bestand.sqlite'], array_values(array_diff(scandir($this->folder) ?: [], ['.', '..'])));
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_sqlite_files_are_not_deleted_here(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->postJson(route('workspace.schemas.apply', $connection), ['operation' => 'drop', 'folder' => (string) realpath($this->folder), 'file' => 'bestand.sqlite'])
            ->assertJsonValidationErrors(['operation' => 'SQLite-Dateien löscht phpdbadmin nicht — das geht im Dateisystem.']);

        $this->assertFileExists($this->folder.'/bestand.sqlite');
    }

    /**
     * Neue Datenbank auf dem PostgreSQL-Server, auf Wunsch mit einem zweiten Schema und einer Tabelle darin.
     */
    private function createPostgresDatabase(Connection $root, string $name, bool $withContent = false): void
    {
        $root->statement('drop database if exists "'.$name.'"');
        $root->statement('create database "'.$name.'"');

        if ($withContent) {
            $factory = app(ConnectionFactory::class);
            $database = $factory->make($this->serverSettings(DatabaseDriver::Postgres, ['database' => $name]));
            $database->statement('create schema extra');
            $database->statement('create table extra.notizen (id integer primary key)');
            $factory->release($database);
        }
    }

    /**
     * @return list<string>
     */
    private function postgresDatabases(Connection $root): array
    {
        return array_map(fn (object $row): string => (string) $row->datname, $root->select('select datname from pg_database'));
    }

    /**
     * Die App-Datenbank liegt (zum Schein) auf dem Testserver unter diesem Namen — wie HostPolicy::fromConfig() sie sähe.
     */
    private function pretendAppDatabase(DatabaseDriver $driver, string $name): void
    {
        $port = $this->serverSettings($driver)->port();
        $this->app->bind(HostPolicy::class, fn (): HostPolicy => new HostPolicy(['127.0.0.1'], [$this->folder], appServer: [
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => (int) $port,
            'database' => $name,
        ]));
        $this->app->forgetScopedInstances();
    }

    private function sqliteConnection(): DatabaseConnection
    {
        $file = $this->folder.'/bestand.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table notes (id integer primary key)');

        return DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create();
    }
}
