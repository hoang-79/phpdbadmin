<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

/**
 * Seite «Schemas vergleichen» (S-08): nur Verbindungen, die das Konto sieht; verglichen wird auf Knopfdruck; das
 * Skript wird nur gezeigt, nie ausgeführt. Die Treiber-Fälle stehen in ShapeComparerTest.
 */
class CompareControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/vergleich-http-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
            'phpdbadmin.connections.connect_timeout' => 1,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('compare'))->assertRedirect(route('login'));
    }

    public function test_page_lists_only_visible_connections(): void
    {
        $own = $this->sqliteConnection(['create table notes (id integer primary key)']);
        $this->sqliteConnection(['create table notes (id integer primary key)'], owner: User::factory()->create());

        $this->actingAs($this->user)->get(route('compare'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('compare/Index')
                ->has('connections', 1)
                ->where('connections.0.id', $own->id)
                ->where('source.connection', null)
                ->where('result', null));
    }

    public function test_choosing_connections_lists_schemas_without_comparing(): void
    {
        $source = $this->sqliteConnection(['create table notes (id integer primary key)']);
        $target = $this->sqliteConnection([]);

        $this->actingAs($this->user)->get(route('compare', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.schemas', ['main'])
                ->where('source.schema', 'main')
                ->where('target.schema', 'main')
                ->where('result', null));
    }

    public function test_compare_shows_differences_and_script_without_running_it(): void
    {
        $source = $this->sqliteConnection([
            'create table notes (id integer primary key autoincrement not null, body text not null, tag varchar)',
            'create index notes_tag_index on notes (tag)',
        ]);
        $target = $this->sqliteConnection(['create table notes (id integer primary key autoincrement not null, body text)']);

        $this->actingAs($this->user)->get(route('compare', ['source' => $source->id, 'target' => $target->id, 'run' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('result.error', null)
                ->where('result.tables.0.name', 'notes')
                ->where('result.tables.0.status', 'changed')
                ->where('result.tables.0.items.0.name', 'body')
                ->where('result.tables.0.items.0.changes', ['NULL'])
                ->where('result.tables.0.items.1.name', 'tag')
                ->where('result.tables.0.items.1.status', 'added')
                ->where('result.tables.0.items.2.kind', 'index')
                ->where('result.script', fn (string $script): bool => str_contains($script, 'alter table "notes" add column "tag" varchar')
                    && str_contains($script, 'create index "notes_tag_index" on "notes" ("tag")'))
                ->where('result.editorUrl', fn (string $url): bool => str_starts_with($url, route('workspace.sql', $target))));

        $columns = (new PDO('sqlite:'.$target->sqlite_path))->query('select name from pragma_table_info(\'notes\')')?->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['id', 'body'], $columns, 'Das Skript wird nur gezeigt, nicht ausgeführt');
    }

    public function test_identical_schemas_report_no_difference(): void
    {
        $tables = ['create table notes (id integer primary key autoincrement not null, body text)'];
        $source = $this->sqliteConnection($tables);
        $target = $this->sqliteConnection($tables);

        $this->actingAs($this->user)->get(route('compare', ['source' => $source->id, 'target' => $target->id, 'run' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('result.tables', [])
                ->where('result.identical', 1)
                ->where('result.script', null));
    }

    public function test_connection_of_other_account_is_404(): void
    {
        $foreign = $this->sqliteConnection([], owner: User::factory()->create());

        $this->actingAs($this->user)->get(route('compare', ['source' => $foreign->id]))->assertNotFound();
    }

    public function test_connection_without_stored_password_asks_for_it(): void
    {
        $server = DatabaseConnection::factory()->for($this->user, 'owner')->create(['store_password' => false, 'password' => null]);

        $this->actingAs($this->user)->get(route('compare', ['source' => $server->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.problem.kind', 'password')
                ->where('source.problem.passwordUrl', route('workspace.password', $server))
                ->where('source.schemas', []));
    }

    /**
     * @param  list<string>  $statements
     */
    private function sqliteConnection(array $statements, ?User $owner = null): DatabaseConnection
    {
        $file = $this->folder.'/vergleich-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        return DatabaseConnection::factory()->for($owner ?? $this->user, 'owner')->sqlite($file)->create();
    }
}
