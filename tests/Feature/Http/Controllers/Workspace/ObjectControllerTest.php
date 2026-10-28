<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_data_page_brings_first_page_columns_and_operators(): void
    {
        $connection = $this->sqliteConnection(rows: 3);

        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Data')
                ->where('selected', 'notes')
                ->where('object.kind', 'table')
                ->where('columns.0.name', 'id')
                ->where('columns.0.primary', true)
                ->where('grid.rows.0', [1, 'Notiz 1'])
                ->where('grid.keys.2', ['3'])
                ->where('grid.total', ['count' => 3, 'exact' => true])
                ->missing('grid.columns')
                ->where('gridError', null)
                ->where('operators.0.value', 'eq')
                ->where('view', ['hidden' => [], 'widths' => []])
                ->where('query', []));
        // Leere Zuordnungen kommen als JSON-Objekt, nicht als Liste — sonst ist `query.sort` die Array-Methode.
        $props = $this->get(route('workspace.object', [$connection, 'main', 'notes']))->viewData('page')['props'];
        $this->assertSame('{}', json_encode($props['query']));
        $this->assertSame('{}', json_encode($props['view']['widths']));
    }

    public function test_data_page_keeps_filters_from_the_address(): void
    {
        $connection = $this->sqliteConnection(rows: 5);

        $this->actingAs($this->user)
            ->get(route('workspace.object', [$connection, 'main', 'notes', 'sort' => '-id', 'f' => [['c' => 'body', 'o' => 'contains', 'v' => 'Notiz']]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.keys.0', ['5'])
                ->where('query.sort', '-id')
                ->where('query.f.0', ['c' => 'body', 'o' => 'contains', 'v' => 'Notiz']));
    }

    public function test_invalid_where_shows_error_card_instead_of_failing(): void
    {
        $this->actingAs($this->user)
            ->get(route('workspace.object', [$this->sqliteConnection(), 'main', 'notes', 'where' => 'gibt_es_nicht = 1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid', null)
                ->where('gridError.reason', 'sql')
                ->whereType('gridError.serverMessage', 'string'));
    }

    public function test_more_rows_count_and_single_row_come_as_json(): void
    {
        $connection = $this->sqliteConnection(rows: 5);
        $this->actingAs($this->user);

        $this->getJson(route('workspace.object.rows', [$connection, 'main', 'notes', 'after' => ['2'], 'limit' => 2]))
            ->assertOk()
            ->assertJsonPath('keys', [['3'], ['4']])
            ->assertJsonPath('next', ['after' => ['4']])
            ->assertJsonMissingPath('columns');

        $this->getJson(route('workspace.object.count', [$connection, 'main', 'notes', 'where' => 'id > 1']))
            ->assertOk()
            ->assertExactJson(['count' => 4]);

        $this->getJson(route('workspace.object.row', [$connection, 'main', 'notes', 'key' => ['3']]))
            ->assertOk()
            ->assertJsonPath('values', [3, 'Notiz 3']);

        $this->getJson(route('workspace.object.row', [$connection, 'main', 'notes', 'key' => ['99']]))->assertNotFound();
        $this->getJson(route('workspace.object.rows', [$connection, 'main', 'notes', 'where' => 'kaputt ==']))
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'query');
    }

    public function test_json_endpoints_respect_session_and_ownership(): void
    {
        $foreign = $this->sqliteConnection(owner: User::factory()->create());

        $this->getJson(route('workspace.object.rows', [$foreign, 'main', 'notes']))->assertUnauthorized();
        $this->actingAs($this->user)->getJson(route('workspace.object.rows', [$foreign, 'main', 'notes']))->assertNotFound();
        $this->actingAs($this->user)->putJson(route('workspace.object.view', [$foreign, 'main', 'notes']), ['hidden' => [], 'widths' => []])->assertNotFound();
    }

    public function test_errors_of_the_app_database_are_not_shown_as_connection_problems(): void
    {
        $connection = $this->sqliteConnection();
        Schema::drop('table_views');

        $this->withoutExceptionHandling()->expectException(QueryException::class);

        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'notes']));
    }

    public function test_view_is_remembered_per_account(): void
    {
        $connection = $this->sqliteConnection();
        $other = User::factory()->create();
        $connection->members()->attach($other, ['permission' => 'read']);
        $connection->update(['shared' => true]);

        $this->actingAs($this->user)
            ->putJson(route('workspace.object.view', [$connection, 'main', 'notes']), ['hidden' => ['body'], 'widths' => ['id' => 80]])
            ->assertNoContent();

        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertInertia(fn (Assert $page) => $page->where('view', ['hidden' => ['body'], 'widths' => ['id' => 80]]));
        $this->actingAs($other)->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertInertia(fn (Assert $page) => $page->where('view', ['hidden' => [], 'widths' => []]));

        $this->actingAs($this->user)
            ->putJson(route('workspace.object.view', [$connection, 'main', 'notes']), ['hidden' => [], 'widths' => ['id' => 5]])
            ->assertJsonValidationErrors('widths.id');
    }

    private function sqliteConnection(?User $owner = null, int $rows = 0): DatabaseConnection
    {
        $file = $this->folder.'/notizen-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text not null)');
        $pdo->exec('create view open_notes as select id from notes');
        for ($index = 1; $index <= $rows; $index++) {
            $pdo->exec("insert into notes (body) values ('Notiz ".$index."')");
        }

        return DatabaseConnection::factory()->for($owner ?? $this->user, 'owner')->sqlite($file)->create();
    }
}
