<?php

namespace Tests\Feature\Http\Controllers\Workspace\Schema;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Datenbank bzw. Schema anlegen und löschen (S-05) je Treiber: MySQL und MariaDB eine Datenbank (nur über eine
 * Verbindung ohne festen Datenbanknamen), PostgreSQL ein Schema, SQLite eine neue Datei in einem erlaubten Ordner.
 * Löschen nur mit dem eingetippten Namen. Datenbanken heissen «phpdbadmin_s05_…» und werden im Test wieder entfernt.
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

    private function sqliteConnection(): DatabaseConnection
    {
        $file = $this->folder.'/bestand.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table notes (id integer primary key)');

        return DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create();
    }
}
