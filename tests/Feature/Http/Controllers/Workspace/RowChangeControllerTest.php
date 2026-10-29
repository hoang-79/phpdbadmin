<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

/**
 * Bearbeiten über die Endpunkte (D-04, D-09, E-05, V-11): Rechte, Schreibschutz und Freigabe auf Zeit, Produktion mit
 * Namen, Rückgängig, Kopieren.
 */
class RowChangeControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/aendern-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_preview_shows_sql_and_writes_nothing(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)
            ->postJson(route('workspace.object.changes.preview', [$connection, 'main', 'notes']), ['changes' => [['op' => 'update', 'key' => ['1'], 'values' => ['body' => 'neu']]]])
            ->assertOk()
            ->assertJsonPath('statements.0.op', 'update')
            ->assertJsonPath('statements.0.sql', 'update "main"."notes" set "body" = \'neu\' where "id" = \'1\'');

        $this->assertSame(['eins', 'zwei'], $this->bodies($connection));
    }

    public function test_owner_applies_and_undoes(): void
    {
        $connection = $this->connection();

        $auditId = $this->actingAs($this->user)
            ->postJson(route('workspace.object.changes', [$connection, 'main', 'notes']), ['changes' => [
                ['op' => 'update', 'key' => ['1'], 'values' => ['body' => 'geändert']],
                ['op' => 'delete', 'key' => ['2']],
            ]])
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('deleted', 1)
            ->json('auditId');
        $this->assertSame(['geändert'], $this->bodies($connection));

        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertInertia(fn (Assert $page) => $page->where('lastChange.auditId', $auditId)->where('lastChange.rows', 2));

        $this->actingAs($this->user)
            ->postJson(route('workspace.object.changes.undo', [$connection, 'main', 'notes', $auditId]))
            ->assertOk()
            ->assertJsonPath('rows', 2);
        $this->assertSame(['eins', 'zwei'], $this->bodies($connection));
    }

    public function test_reader_may_neither_change_nor_unlock(): void
    {
        $connection = $this->connection(['shared' => true]);
        $reader = User::factory()->create();
        $connection->members()->attach($reader, ['permission' => 'read']);

        $this->actingAs($reader)->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertInertia(fn (Assert $page) => $page->where('editing', ['allowed' => false, 'reason' => 'right']));
        $this->actingAs($reader)
            ->postJson(route('workspace.object.changes', [$connection, 'main', 'notes']), ['changes' => [['op' => 'delete', 'key' => ['1']]]])
            ->assertForbidden();
        $this->actingAs($reader)->post(route('workspace.unlock', $connection), ['reason' => 'Fehler beheben'])->assertForbidden();

        $this->assertSame(['eins', 'zwei'], $this->bodies($connection));
    }

    public function test_read_only_connection_can_be_unlocked_for_fifteen_minutes(): void
    {
        $connection = $this->connection(['read_only' => true]);
        $change = ['changes' => [['op' => 'update', 'key' => ['1'], 'values' => ['body' => 'mit Freigabe']]]];

        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertInertia(fn (Assert $page) => $page->where('editing.reason', 'readonly'));
        $this->actingAs($this->user)->postJson(route('workspace.object.changes', [$connection, 'main', 'notes']), $change)
            ->assertForbidden()
            ->assertJsonPath('reason', 'readonly');

        $this->actingAs($this->user)->post(route('workspace.unlock', $connection), ['reason' => 'Tippfehler im Namen korrigieren'])->assertRedirect();
        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('editing.allowed', true)
                ->whereType('workspace.connection.unlockedUntil', 'string'));
        $this->actingAs($this->user)->postJson(route('workspace.object.changes', [$connection, 'main', 'notes']), $change)->assertOk();

        $entry = AuditEntry::query()->where('action', 'connection.unlock')->sole();
        $this->assertSame('Tippfehler im Namen korrigieren', $entry->context['reason'] ?? null);
        $this->assertNotNull($entry->context['until'] ?? null);

        $this->travel(16)->minutes();

        $this->actingAs($this->user)->postJson(route('workspace.object.changes', [$connection, 'main', 'notes']), $change)->assertForbidden();
        $this->assertSame(['mit Freigabe', 'zwei'], $this->bodies($connection));
    }

    public function test_unlock_can_be_withdrawn_and_needs_a_reason(): void
    {
        $connection = $this->connection(['read_only' => true]);

        $this->actingAs($this->user)->post(route('workspace.unlock', $connection), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->user)->post(route('workspace.unlock', $connection), ['reason' => 'kurz prüfen']);
        $this->actingAs($this->user)->delete(route('workspace.unlock.destroy', $connection))->assertRedirect();

        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertInertia(fn (Assert $page) => $page->where('editing.reason', 'readonly')->where('workspace.connection.unlockedUntil', null));
        $this->assertSame(1, AuditEntry::query()->where('action', 'connection.lock')->count());
    }

    public function test_production_needs_the_name_of_the_connection(): void
    {
        $connection = $this->connection(['environment' => 'production', 'read_only' => false]);
        $change = ['changes' => [['op' => 'delete', 'key' => ['1']]]];

        $this->actingAs($this->user)->postJson(route('workspace.object.changes', [$connection, 'main', 'notes']), $change)->assertConflict();
        $this->actingAs($this->user)->postJson(route('workspace.object.changes', [$connection, 'main', 'notes']), [...$change, 'confirmation' => $connection->name])->assertOk();

        $this->assertSame(['zwei'], $this->bodies($connection));
    }

    public function test_tables_without_key_and_views_stay_read_only(): void
    {
        $connection = $this->connection();
        (new PDO('sqlite:'.$connection->sqlite_path))->exec("create table lines (line text); insert into lines values ('a'), ('a')");

        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'lines']))
            ->assertInertia(fn (Assert $page) => $page->where('editing.reason', 'key'));
        $this->actingAs($this->user)->get(route('workspace.object', [$connection, 'main', 'notes_view']))
            ->assertInertia(fn (Assert $page) => $page->where('editing.reason', 'view'));
        $this->actingAs($this->user)
            ->postJson(route('workspace.object.changes', [$connection, 'main', 'lines']), ['changes' => [['op' => 'update', 'key' => ['a'], 'values' => ['line' => 'b']]]])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'rejected');
    }

    public function test_copies_a_row_or_the_view_as_tsv_markdown_and_insert(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->getJson(route('workspace.object.copy', [$connection, 'main', 'notes', 'format' => 'tsv']))
            ->assertOk()
            ->assertJsonPath('text', "id\tbody\n1\teins\n2\tzwei\n")
            ->assertJsonPath('rows', 2);
        $this->actingAs($this->user)->getJson(route('workspace.object.copy', [$connection, 'main', 'notes', 'format' => 'insert', 'key' => ['2']]))
            ->assertJsonPath('text', "INSERT INTO \"notes\" (\"id\", \"body\") VALUES (2, 'zwei');\n");
        $this->actingAs($this->user)->getJson(route('workspace.object.copy', [$connection, 'main', 'notes', 'format' => 'markdown', 'f' => [['c' => 'id', 'o' => 'eq', 'v' => '1']]]))
            ->assertJsonPath('text', "| id | body |\n| --- | --- |\n| 1 | eins |\n");
        $this->actingAs($this->user)->getJson(route('workspace.object.copy', [$connection, 'main', 'notes', 'format' => 'csv']))->assertUnprocessable();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connection(array $attributes = []): DatabaseConnection
    {
        $file = $this->folder.'/notizen-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text not null)');
        $pdo->exec("insert into notes (body) values ('eins'), ('zwei')");
        $pdo->exec('create view notes_view as select * from notes');

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
