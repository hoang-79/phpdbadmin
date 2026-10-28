<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Struktur ansehen im Arbeitsbildschirm (S-01, Wireframe 08): Seite mit Navigator, gewähltem Objekt und Struktur.
 */
class ObjectControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/objekt-'.getmypid());
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

    public function test_structure_page_shows_table_with_navigator_and_selection(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->get(route('workspace.object.structure', [$connection, 'main', 'notes']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Structure')
                ->where('selected', 'notes')
                ->where('workspace.schema', 'main')
                ->where('workspace.groups.0.items.0.name', 'notes')
                ->where('structure.object.kind', 'table')
                ->where('structure.columns.0.name', 'id')
                ->where('structure.columns.0.primary', true)
                ->where('structure.columns.1.kind', 'text')
                ->where('structure.columns.1.kindLabel', 'Text')
                ->where('structure.ddl', fn (string $ddl) => str_starts_with(strtolower($ddl), 'create table')));
    }

    public function test_view_has_columns_and_definition(): void
    {
        $this->actingAs($this->user)->get(route('workspace.object.structure', [$this->sqliteConnection(), 'main', 'open_notes']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('structure.object.kind', 'view')
                ->where('structure.columns.0.name', 'id')
                ->where('structure.indexes', []));
    }

    public function test_unknown_object_or_schema_is_404(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->get(route('workspace.object.structure', [$connection, 'main', 'gibt_es_nicht']))->assertNotFound();
        $this->actingAs($this->user)->get(route('workspace.object.structure', [$connection, 'anderes', 'notes']))->assertNotFound();
    }

    public function test_connection_of_other_account_is_404(): void
    {
        $foreign = $this->sqliteConnection(owner: User::factory()->create());

        $this->actingAs($this->user)->get(route('workspace.object.structure', [$foreign, 'main', 'notes']))->assertNotFound();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('workspace.object.structure', [$this->sqliteConnection(), 'main', 'notes']))->assertRedirect(route('login'));
    }

    public function test_postgres_with_all_databases_reads_structure_in_chosen_database(): void
    {
        $settings = $this->scratchSettings(DatabaseDriver::Postgres, $this->folder);
        $this->createSampleSchema(app(ConnectionFactory::class)->make($settings), DatabaseDriver::Postgres);
        $connection = $this->savedConnection($settings->withDatabase(null), $this->user);

        $this->actingAs($this->user)
            ->get(route('workspace.object.structure', [$connection, 'public', 'orders', 'db' => $this->targetTestDatabase()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.database', $this->targetTestDatabase())
                ->where('structure.foreignKeys.0.foreignTable', 'customers'));
    }

    private function sqliteConnection(?User $owner = null): DatabaseConnection
    {
        $file = $this->folder.'/notizen-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text not null)');
        $pdo->exec('create view open_notes as select id from notes');

        return DatabaseConnection::factory()->for($owner ?? $this->user, 'owner')->sqlite($file)->create();
    }
}
