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
 * Arbeitsbildschirm öffnen (Wireframe 05, O-01, V-04): Navigator, Umgebung, Passwort je Sitzung, Fehler in Klartext.
 */
class WorkspaceControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/arbeit-'.getmypid());
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

    public function test_opening_a_connection_goes_to_its_default_schema(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->get(route('workspace', $connection))
            ->assertRedirect(route('workspace.schema', [$connection, 'main']));
    }

    public function test_schema_page_shows_navigator_groups_and_environment(): void
    {
        $connection = $this->sqliteConnection(environment: 'production');

        $this->actingAs($this->user)->get(route('workspace.schema', [$connection, 'main']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Schema')
                ->where('workspace.connection.environment', 'production')
                ->where('workspace.connection.readOnly', true)
                ->where('workspace.schema', 'main')
                ->where('workspace.schemas', ['main'])
                ->where('workspace.groups.0.key', 'tables')
                ->where('workspace.groups.0.items.0.name', 'notes')
                ->where('workspace.groups.1.items.0.name', 'open_notes')
                ->where('workspace.groups.2.key', 'routines')
                ->whereType('workspace.groups.2.note', 'string')
                ->missing('workspace.connection.password'));
    }

    public function test_unknown_schema_is_404(): void
    {
        $this->actingAs($this->user)->get(route('workspace.schema', [$this->sqliteConnection(), 'gibt_es_nicht']))
            ->assertNotFound();
    }

    public function test_connection_of_other_account_is_404(): void
    {
        $foreign = $this->sqliteConnection(owner: User::factory()->create());

        $this->actingAs($this->user)->get(route('workspace', $foreign))->assertNotFound();
        $this->actingAs($this->user)->get(route('workspace.schema', [$foreign, 'main']))->assertNotFound();
    }

    public function test_missing_file_shows_problem_instead_of_error_page(): void
    {
        $connection = $this->sqliteConnection();
        unlink($connection->sqlite_path);

        $this->actingAs($this->user)->get(route('workspace', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connect/Unavailable')
                ->where('problem.kind', 'file_missing')
                ->where('connection.canManage', true));
    }

    public function test_unreachable_server_shows_problem(): void
    {
        $connection = DatabaseConnection::factory()->for($this->user, 'owner')->create([
            'driver' => DatabaseDriver::MariaDb, 'host' => '127.0.0.1', 'port' => 1, 'password' => 'Geheim-Arbeit-1',
        ]);

        $response = $this->actingAs($this->user)->get(route('workspace', $connection));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('connect/Unavailable')
            ->where('problem.kind', 'host_unreachable'));
        $this->assertStringNotContainsString('Geheim-Arbeit-1', (string) $response->getContent());
    }

    public function test_navigator_with_a_thousand_tables_stays_fast(): void
    {
        $file = $this->folder.'/tausend.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('begin');
        for ($index = 1; $index <= 1000; $index++) {
            $pdo->exec(sprintf('create table tabelle_%04d (id integer primary key)', $index));
        }
        $pdo->exec('commit');
        $connection = DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create();

        $start = hrtime(true);
        $response = $this->actingAs($this->user)->get(route('workspace.schema', [$connection, 'main']));
        $milliseconds = (hrtime(true) - $start) / 1e6;

        $response->assertInertia(fn (Assert $page) => $page->has('workspace.groups.0.items', 1000));
        $this->assertLessThan(1000, $milliseconds, 'Navigator mit 1000 Tabellen brauchte '.round($milliseconds).' ms');
    }

    public function test_without_stored_password_asks_once_per_session(): void
    {
        $reader = $this->readAccount(DatabaseDriver::MariaDb, app(ConnectionFactory::class)->make($this->scratchSettings(DatabaseDriver::MariaDb, $this->folder)));
        $connection = $this->savedConnection($reader, $this->user, ['store_password' => false, 'password' => null]);

        $this->actingAs($this->user)->get(route('workspace', $connection))
            ->assertRedirect(route('workspace.password', $connection));

        $this->post(route('workspace.password.store', $connection), ['password' => 'falsch'])
            ->assertSessionHasErrors('password');

        $this->post(route('workspace.password.store', $connection), ['password' => $reader->password])
            ->assertRedirect(route('workspace', $connection));

        $this->get(route('workspace', $connection))
            ->assertRedirect(route('workspace.schema', [$connection, $this->targetTestDatabase()]));
        $this->assertNull($connection->fresh()?->password);
    }

    public function test_postgres_with_all_databases_switches_database_by_address(): void
    {
        $settings = $this->scratchSettings(DatabaseDriver::Postgres, $this->folder);
        $connection = $this->savedConnection($settings->withDatabase(null), $this->user);

        $this->actingAs($this->user)->get(route('workspace', $connection))
            ->assertRedirect(route('workspace.schema', [$connection, 'public', 'db' => 'postgres']));

        $this->get(route('workspace.schema', [$connection, 'public', 'db' => $this->targetTestDatabase()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.database', $this->targetTestDatabase())
                ->where('workspace.databaseLabel', $this->targetTestDatabase())
                ->has('workspace.databases'));

        $this->get(route('workspace.schema', [$connection, 'public', 'db' => 'gibt_es_nicht']))->assertNotFound();
    }

    /**
     * Fehler aus Hoangs Durchlauf (28.10.2026): «alle Datenbanken» und dann öffnen — die Liste der Datenbanken und
     * die Datenbank «postgres» waren zwei Verbindungen mit gleichem Namen; die zweite trennte die erste, der
     * Neuaufbau lief endlos.
     */
    public function test_postgres_with_all_databases_opens_its_default_database(): void
    {
        $settings = $this->scratchSettings(DatabaseDriver::Postgres, $this->folder);
        $connection = $this->savedConnection($settings->withDatabase(null), $this->user);

        $this->actingAs($this->user)->followingRedirects()->get(route('workspace', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/Schema')
                ->where('workspace.database', 'postgres')
                ->where('workspace.schema', 'public'));
    }

    private function sqliteConnection(?User $owner = null, string $environment = 'local'): DatabaseConnection
    {
        $file = $this->folder.'/notizen-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text)');
        $pdo->exec('create view open_notes as select id from notes');

        return DatabaseConnection::factory()->for($owner ?? $this->user, 'owner')->sqlite($file)->create([
            'environment' => $environment,
            'read_only' => $environment === 'production',
        ]);
    }
}
