<?php

namespace Tests\Feature\Http\Controllers\Workspace\Server;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Wartung (W-05): je Treiber, was er kann; Vorschau, Bestätigung, Ergebnis je Tabelle, genau ein Eintrag im
 * Protokoll. Ziele nur aus dem Katalog.
 */
#[Group('target-databases')]
class MaintenanceControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/wartung-'.getmypid());
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

    public function test_offers_what_sqlite_can_and_loads_tables_on_request(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->get(route('workspace.server.maintenance', [$connection, 'tables_in' => 'main']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/server/Maintenance')
                ->where('operations', [
                    ['key' => 'analyze', 'forTables' => true],
                    ['key' => 'vacuum', 'forTables' => false],
                    ['key' => 'check', 'forTables' => true],
                ])
                ->missing('tables')
                ->reloadOnly('tables', fn (Assert $reload) => $reload->where('tables', ['notes'])));
    }

    public function test_preview_lists_the_statements_and_runs_nothing(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->postJson(route('workspace.server.maintenance.preview', $connection), ['operation' => 'check', 'schema' => 'main', 'table' => 'notes'])
            ->assertOk()
            ->assertJsonPath('target', 'main.notes')
            ->assertJsonPath('statements', ['PRAGMA "main".integrity_check("notes")'])
            ->assertJsonPath('notes', ['Prüft nur und ändert nichts.']);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_integrity_check_shows_result_per_table_and_records_one_entry(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->from(route('workspace.server.maintenance', $connection))
            ->post(route('workspace.server.maintenance.run', $connection), ['operation' => 'check', 'schema' => 'main', 'table' => 'notes'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.server.maintenance', $connection))
            ->assertInertiaFlash('maintenance.results', [['table' => 'notes', 'status' => 'ok', 'message' => 'ok']])
            ->assertInertiaFlash('toast.message', 'Wartung für main.notes abgeschlossen.');

        $entry = AuditEntry::query()->sole();
        $this->assertSame(['server.maintenance', 'main.notes', 'PRAGMA "main".integrity_check("notes")'], [$entry->action, $entry->target, $entry->sql]);
        $this->assertSame('check', $entry->context['operation'] ?? null);
    }

    public function test_sqlite_vacuum_runs_for_the_whole_file_despite_the_attach_guard(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->from(route('workspace.server.maintenance', $connection))
            ->post(route('workspace.server.maintenance.run', $connection), ['operation' => 'vacuum', 'schema' => 'main'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('maintenance.results.0.status', 'ok');

        $this->post(route('workspace.server.maintenance.run', $connection), ['operation' => 'vacuum', 'schema' => 'main', 'table' => 'notes'])
            ->assertSessionHasErrors(['table' => 'VACUUM gilt bei SQLite immer der ganzen Datei.']);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidTargets(): array
    {
        return [
            'Tabelle gibt es nicht' => [['operation' => 'analyze', 'schema' => 'main', 'table' => 'fehlt'], 'table'],
            'Tabelle sieht aus wie SQL' => [['operation' => 'analyze', 'schema' => 'main', 'table' => 'notes"; drop table notes; --'], 'table'],
            'Sicht statt Tabelle' => [['operation' => 'analyze', 'schema' => 'main', 'table' => 'open_notes'], 'table'],
            'Schema gibt es nicht' => [['operation' => 'analyze', 'schema' => 'aussen'], 'schema'],
            'Wartung des Treibers' => [['operation' => 'optimize', 'schema' => 'main'], 'operation'],
            'Wartung unbekannt' => [['operation' => 'drop', 'schema' => 'main'], 'operation'],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidTargets')]
    public function test_targets_outside_the_catalog_are_rejected(array $payload, string $field): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->postJson(route('workspace.server.maintenance.preview', $connection), $payload)
            ->assertJsonValidationErrors($field);

        $this->assertSame(['notes', 'open_notes'], $this->objectsIn($connection));
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[DataProvider('serverDrivers')]
    public function test_server_maintenance_of_the_whole_schema_reports_each_table(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $target->statement('create table w05_a (id int primary key)');
        $target->statement('create table w05_b (id int primary key)');
        $connection = $this->savedConnection($settings, $this->user);
        $schema = $driver === DatabaseDriver::Postgres ? 'public' : $this->targetTestDatabase();

        $this->actingAs($this->user)->from(route('workspace.server.maintenance', $connection))
            ->post(route('workspace.server.maintenance.run', $connection), ['operation' => 'analyze', 'schema' => $schema])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('maintenance.target', $schema);

        $results = session('inertia.flash_data.maintenance.results') ?? [];
        $this->assertSame([], array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'error')));
        $this->assertCount(2, array_unique(array_column($results, 'table')));
        $this->assertSame(1, AuditEntry::query()->where('action', 'server.maintenance')->count());
    }

    private function sqliteConnection(): DatabaseConnection
    {
        $file = $this->folder.'/wartung-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text)');
        $pdo->exec('create view open_notes as select id from notes');

        return DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create();
    }

    /**
     * @return list<string>
     */
    private function objectsIn(DatabaseConnection $connection): array
    {
        $pdo = new PDO('sqlite:'.$connection->sqlite_path);

        return $pdo->query("select name from sqlite_master where type in ('table', 'view') order by name")->fetchAll(PDO::FETCH_COLUMN);
    }
}
