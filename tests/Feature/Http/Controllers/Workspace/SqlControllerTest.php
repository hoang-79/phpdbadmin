<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\QueryHistoryEntry;
use App\Models\SavedQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

/**
 * SQL-Editor über die Endpunkte (Q-01 bis Q-04, Q-07): Anweisung an der Schreibmarke, gefährliche Anweisungen nur mit
 * Bestätigung (Produktion mit Namen), Rechte, Verlauf, gespeicherte Abfragen, Protokoll ohne Werte.
 */
class SqlControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/sql-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_page_brings_limits_rights_and_deferred_completion(): void
    {
        $connection = $this->sqliteConnection();
        SavedQuery::query()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'name' => 'Alle Notizen', 'sql' => 'select * from notes']);

        $this->actingAs($this->user)->get(route('workspace.sql', [$connection, 'tab' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Sql')
                ->where('queryTab', 2)
                ->where('editor.canWrite', true)
                ->where('editor.rowLimit', 1000)
                ->where('saved.0.name', 'Alle Notizen')
                ->where('workspace.schema', 'main')
                ->missing('completion')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('completion.notes', ['id', 'body'])));
    }

    public function test_runs_only_the_statement_at_the_cursor_and_records_history(): void
    {
        $connection = $this->sqliteConnection();
        $sql = "select count(*) as n from notes;\nselect body from notes order by id";

        $this->actingAs($this->user)
            ->postJson(route('workspace.sql.run', $connection), ['sql' => $sql, 'mode' => 'statement', 'cursor' => strlen($sql) - 3, 'run' => 'lauf-0001'])
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.rows', [['eins'], ['zwei']])
            ->assertJsonPath('history.0.sql', 'select body from notes order by id');

        $this->assertSame(1, QueryHistoryEntry::query()->where('user_id', $this->user->id)->count());
    }

    public function test_history_keeps_no_passwords(): void
    {
        $connection = $this->sqliteConnection();

        // SQLite kennt PASSWORD() nicht — die Anweisung scheitert, steht aber im Verlauf.
        $this->actingAs($this->user)
            ->postJson(route('workspace.sql.run', $connection), ['sql' => "select password('geheim-4711')", 'mode' => 'all', 'run' => 'lauf-0009'])
            ->assertOk();

        $this->assertSame('select password(?)', QueryHistoryEntry::query()->where('user_id', $this->user->id)->sole()->sql);
    }

    public function test_dangerous_statements_need_confirmation_and_are_logged_without_values(): void
    {
        $connection = $this->sqliteConnection();
        $payload = ['sql' => "update notes set body = 'geheim'", 'mode' => 'all', 'run' => 'lauf-0002'];

        $this->actingAs($this->user)->postJson(route('workspace.sql.run', $connection), $payload)
            ->assertConflict()
            ->assertJsonPath('reason', 'confirm')
            ->assertJsonPath('dangers.0.sql', "update notes set body = 'geheim'");
        $this->assertSame(['eins', 'zwei'], $this->bodies($connection));

        $this->actingAs($this->user)->postJson(route('workspace.sql.run', $connection), [...$payload, 'confirmed' => true])
            ->assertOk()
            ->assertJsonPath('results.0.affected', 2);
        $this->assertSame(['geheim', 'geheim'], $this->bodies($connection));

        $entry = AuditEntry::query()->where('action', 'sql.execute')->sole();
        $this->assertSame('update notes set body = ?', $entry->sql);
        $this->assertStringNotContainsString('geheim', json_encode($entry->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_production_needs_the_name_of_the_connection(): void
    {
        $connection = $this->sqliteConnection(['environment' => 'production', 'read_only' => false]);
        $payload = ['sql' => 'delete from notes', 'mode' => 'all', 'run' => 'lauf-0003', 'confirmed' => true];

        $this->actingAs($this->user)->postJson(route('workspace.sql.run', $connection), [...$payload, 'confirmation' => 'falsch'])
            ->assertConflict()
            ->assertJsonPath('wrongName', true);
        $this->actingAs($this->user)->postJson(route('workspace.sql.run', $connection), [...$payload, 'confirmation' => $connection->name])
            ->assertOk();

        $this->assertSame([], $this->bodies($connection));
    }

    public function test_reader_cannot_write(): void
    {
        $connection = $this->sqliteConnection(['shared' => true]);
        $reader = User::factory()->create();
        $connection->members()->attach($reader, ['permission' => 'read']);

        $this->actingAs($reader)->get(route('workspace.sql', $connection))
            ->assertInertia(fn (Assert $page) => $page->where('editor.canWrite', false));
        $this->actingAs($reader)
            ->postJson(route('workspace.sql.run', $connection), ['sql' => "insert into notes (body) values ('neu')", 'mode' => 'all', 'run' => 'lauf-0004'])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'error');

        $this->assertSame(['eins', 'zwei'], $this->bodies($connection));
    }

    public function test_only_comments_is_nothing_to_run(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('workspace.sql.run', $this->sqliteConnection()), ['sql' => '-- nichts', 'mode' => 'all', 'run' => 'lauf-0005'])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'empty');
    }

    public function test_saved_queries_and_history_belong_to_their_account(): void
    {
        $connection = $this->sqliteConnection(['shared' => true]);
        $other = User::factory()->create();
        $connection->members()->attach($other, ['permission' => 'read']);
        QueryHistoryEntry::query()->create(['user_id' => $other->id, 'connection_id' => $connection->id, 'sql' => 'select fremd', 'status' => 'ok']);
        QueryHistoryEntry::query()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'sql' => 'select eigen', 'status' => 'ok']);

        $this->actingAs($this->user)->getJson(route('workspace.sql.history', [$connection, 'q' => 'select']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.sql', 'select eigen');

        $saved = $this->actingAs($this->user)
            ->postJson(route('workspace.sql.saved.store', $connection), ['name' => 'Bericht', 'sql' => 'select 1'])
            ->assertCreated()
            ->json('id');
        $this->actingAs($other)->deleteJson(route('workspace.sql.saved.destroy', [$connection, $saved]))->assertNotFound();
        $this->actingAs($this->user)->deleteJson(route('workspace.sql.saved.destroy', [$connection, $saved]))->assertNoContent();
        $this->assertSame(0, SavedQuery::query()->count());
    }

    public function test_cancel_and_foreign_connections(): void
    {
        $connection = $this->sqliteConnection();
        $foreign = $this->sqliteConnection(['owner_id' => User::factory()->create()->id]);
        $this->postJson(route('workspace.sql.run', $connection), ['sql' => 'select 1', 'mode' => 'all', 'run' => 'lauf-0008'])->assertUnauthorized();

        $this->actingAs($this->user)->postJson(route('workspace.sql.cancel', $connection), ['run' => 'lauf-0006'])->assertNoContent();
        $this->actingAs($this->user)->postJson(route('workspace.sql.cancel', $connection), ['run' => "x'; drop"])->assertUnprocessable();
        $this->actingAs($this->user)->postJson(route('workspace.sql.run', $foreign), ['sql' => 'select 1', 'mode' => 'all', 'run' => 'lauf-0007'])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sqliteConnection(array $attributes = []): DatabaseConnection
    {
        $file = $this->folder.'/notizen-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text not null)');
        $pdo->exec("insert into notes (body) values ('eins'), ('zwei')");

        return DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create($attributes);
    }

    /**
     * @return list<string>
     */
    private function bodies(DatabaseConnection $connection): array
    {
        return (new PDO('sqlite:'.$connection->sqlite_path))->query('select body from notes order by id')->fetchAll(PDO::FETCH_COLUMN);
    }
}
