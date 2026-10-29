<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

/**
 * Seite «Laravel-Migration» einer Tabelle (S-09): nur Tabellen, nur sichtbare Verbindungen. Wie die Migration auf
 * anderen Treibern wirkt, belegt MigrationWriterTest.
 */
class MigrationControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/migration-http-'.getmypid());
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

    public function test_shows_migration_for_a_table(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->get(route('workspace.object.migration', [$connection, 'main', 'notes']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Migration')
                ->where('object.name', 'notes')
                ->where('migration.fileName', fn (string $name): bool => str_ends_with($name, '_create_notes_table.php'))
                ->where('migration.code', fn (string $code): bool => str_contains($code, "Schema::create('notes'")
                    && str_contains($code, "\$table->text('body');")
                    && str_contains($code, "\$table->foreign('author_id')->references('id')->on('authors');"))
                ->where('workspace.connection.id', $connection->id));
    }

    public function test_view_has_no_migration(): void
    {
        $this->actingAs($this->user)->get(route('workspace.object.migration', [$this->sqliteConnection(), 'main', 'open_notes']))
            ->assertNotFound();
    }

    public function test_connection_of_other_account_is_404(): void
    {
        $foreign = $this->sqliteConnection(User::factory()->create());

        $this->actingAs($this->user)->get(route('workspace.object.migration', [$foreign, 'main', 'notes']))
            ->assertNotFound();
    }

    private function sqliteConnection(?User $owner = null): DatabaseConnection
    {
        $file = $this->folder.'/notizen-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table authors (id integer primary key autoincrement not null, name varchar not null)');
        $pdo->exec('create table notes (id integer primary key autoincrement not null, author_id integer not null references authors (id), body text not null)');
        $pdo->exec('create view open_notes as select id from notes');

        return DatabaseConnection::factory()->for($owner ?? $this->user, 'owner')->sqlite($file)->create();
    }
}
