<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Enums\ConnectionEnvironment;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

/**
 * Suchen und Ersetzen über die Endpunkte (D-08): Texte kommen wörtlich an, Rechte, Schreibschutz, Produktion mit
 * Namen, Rückgängig über das Raster; dazu die Seite «Suchen» über ein ganzes Schema.
 */
class SearchReplaceControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/ersetzen-seite-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_texts_arrive_literally_and_the_change_can_be_undone_from_the_grid(): void
    {
        $connection = $this->connection();
        $payload = ['columns' => ['body'], 'search' => ' alt ', 'replace' => '', 'match_case' => true, 'whole_value' => false];

        $this->actingAs($this->user)
            ->postJson(route('workspace.object.replace.preview', [$connection, 'main', 'notes']), $payload)
            ->assertOk()
            ->assertJsonPath('rows', 1)
            ->assertJsonPath('samples.0.after', 'einsdrei');

        $auditId = $this->postJson(route('workspace.object.replace', [$connection, 'main', 'notes']), $payload)
            ->assertOk()
            ->assertJsonPath('rows', 1)
            ->assertJsonPath('undoable', true)
            ->json('auditId');
        $this->assertSame(['einsdrei', 'zwei alt'], $this->bodies($connection));

        $this->get(route('workspace.object', [$connection, 'main', 'notes']))
            ->assertInertia(fn (Assert $page) => $page->where('lastChange.auditId', $auditId));
        $this->postJson(route('workspace.object.changes.undo', [$connection, 'main', 'notes', $auditId]))->assertOk();
        $this->assertSame(['eins alt drei', 'zwei alt'], $this->bodies($connection));
    }

    public function test_reader_may_preview_but_not_apply(): void
    {
        $connection = $this->connection(['shared' => true]);
        $reader = User::factory()->create();
        $connection->members()->attach($reader, ['permission' => 'read']);
        $payload = ['columns' => ['body'], 'search' => 'alt', 'replace' => 'neu'];

        $this->actingAs($reader)->postJson(route('workspace.object.replace.preview', [$connection, 'main', 'notes']), $payload)->assertOk()->assertJsonPath('rows', 2);
        $this->actingAs($reader)->postJson(route('workspace.object.replace', [$connection, 'main', 'notes']), $payload)->assertForbidden();

        $this->assertSame(['eins alt drei', 'zwei alt'], $this->bodies($connection));
    }

    public function test_read_only_and_production_connections_need_more_than_the_right(): void
    {
        $payload = ['columns' => ['body'], 'search' => 'alt', 'replace' => 'neu'];
        $readOnly = $this->connection(['read_only' => true]);

        $this->actingAs($this->user)->postJson(route('workspace.object.replace', [$readOnly, 'main', 'notes']), $payload)
            ->assertForbidden()
            ->assertJsonPath('reason', 'readonly');

        $production = $this->connection(['environment' => ConnectionEnvironment::Production, 'read_only' => false, 'name' => 'Live']);
        $this->postJson(route('workspace.object.replace', [$production, 'main', 'notes']), $payload)
            ->assertConflict()
            ->assertJsonPath('reason', 'confirm');
        $this->postJson(route('workspace.object.replace', [$production, 'main', 'notes']), [...$payload, 'confirmation' => 'Live'])->assertOk();

        $this->assertSame(['eins alt drei', 'zwei alt'], $this->bodies($readOnly));
        $this->assertSame(['eins neu drei', 'zwei neu'], $this->bodies($production));
    }

    public function test_empty_search_and_unsuitable_columns_are_refused(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('workspace.object.replace.preview', [$connection, 'main', 'notes']), ['columns' => ['body'], 'search' => '', 'replace' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Geben Sie einen Suchtext ein.');
        $this->postJson(route('workspace.object.replace.preview', [$connection, 'main', 'notes']), ['columns' => ['id'], 'search' => '1', 'replace' => '2'])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'rejected');
    }

    public function test_search_page_lists_hits_per_table_and_foreign_connections_are_404(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->get(route('workspace.search', [$connection, 'q' => 'ALT']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Search')
                ->where('term', 'ALT')
                ->where('outcome.tables.0.table', 'notes')
                ->where('outcome.tables.0.hits', 2)
                ->where('outcome.stoppedBy', null));

        $this->get(route('workspace.search', $connection))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', null));

        $foreign = DatabaseConnection::factory()->sqlite($this->folder.'/fremd.sqlite')->create();
        $this->get(route('workspace.search', [$foreign, 'q' => 'x']))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connection(array $attributes = []): DatabaseConnection
    {
        $file = $this->folder.'/notizen-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text not null)');
        $pdo->exec("insert into notes (body) values ('eins alt drei'), ('zwei alt')");

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
