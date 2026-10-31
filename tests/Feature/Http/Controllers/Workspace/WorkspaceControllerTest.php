<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Connections\ConnectionFactory;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Support\SchemaOverview;
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

    /**
     * Gestaltung § 3: Mit einer Verbindung der Umgebung Produktion ist das Favicon rot — schon auf der ersten Seite;
     * beide Fassungen stehen an den Links, damit die App beim Wechsel tauschen kann.
     */
    public function test_production_connection_shows_the_red_favicon_and_other_environments_the_usual_one(): void
    {
        $icon = static fn (string $file): string => '/'.$file.'?v='.substr((string) md5_file(public_path($file)), 0, 8);
        $page = fn (string $environment): string => (string) $this->actingAs($this->user)
            ->get(route('workspace.schema', [$this->sqliteConnection(environment: $environment), 'main']))
            ->assertOk()->getContent();

        $production = $page('production');
        $this->assertStringContainsString('href="'.$icon('favicon-produktion.svg').'" type="image/svg+xml"', $production);
        $this->assertStringContainsString('href="'.$icon('favicon-produktion.ico').'" sizes="any"', $production);
        foreach (['test', 'local'] as $environment) {
            $html = $page($environment);
            $this->assertStringContainsString('href="'.$icon('favicon.svg').'" type="image/svg+xml"', $html, $environment);
            $this->assertStringContainsString('data-default="'.$icon('favicon.svg').'" data-production="'.$icon('favicon-produktion.svg').'"', $html, $environment);
        }
        $this->assertFileEquals(resource_path('brand/favicon-produktion.svg'), public_path('favicon-produktion.svg'));
        $this->assertFileEquals(resource_path('brand/png/favicon-produktion.ico'), public_path('favicon-produktion.ico'));
    }

    public function test_schema_page_brings_the_saved_overview_settings_and_the_filter_of_this_schema(): void
    {
        $connection = $this->sqliteConnection();
        SchemaOverview::save($this->user, ['view' => 'compact', 'sort' => 'rows', 'direction' => 'desc', 'perPage' => 25, 'kind' => 'views'], $connection->id, null, 'main', 'note');

        $this->actingAs($this->user)->get(route('workspace.schema', [$connection, 'main']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview', ['view' => 'compact', 'sort' => 'rows', 'direction' => 'desc', 'perPage' => 25, 'kind' => 'views', 'filter' => 'note'])
                ->has('workspace.groups.0.items.0', fn (Assert $item) => $item
                    ->where('name', 'notes')
                    ->has('size')
                    ->has('comment')
                    ->etc()));
    }

    public function test_schema_page_starts_with_the_default_overview(): void
    {
        $this->actingAs($this->user)->get(route('workspace.schema', [$this->sqliteConnection(), 'main']))
            ->assertInertia(fn (Assert $page) => $page->where('overview', [...SchemaOverview::DEFAULTS, 'filter' => '']));
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

    public function test_tells_the_page_whether_the_structure_may_be_changed(): void
    {
        $own = $this->sqliteConnection();
        $readOnly = $this->sqliteConnection(environment: 'production');
        $shared = $this->sqliteConnection(owner: User::factory()->create());
        $shared->update(['shared' => true]);
        $shared->members()->attach($this->user, ['permission' => ConnectionPermission::Read->value]);
        $this->actingAs($this->user);

        $this->get(route('workspace.schema', [$own, 'main']))->assertInertia(fn (Assert $page) => $page->where('workspace.connection.canWrite', true));
        // Recht vorhanden, aber schreibgeschützt: die Seite sperrt über readOnly, bis der Schutz auf Zeit aufgehoben ist (V-11).
        $this->get(route('workspace.schema', [$readOnly, 'main']))->assertInertia(fn (Assert $page) => $page
            ->where('workspace.connection.canWrite', true)
            ->where('workspace.connection.readOnly', true)
            ->where('workspace.connection.unlockedUntil', null));
        $this->get(route('workspace.schema', [$shared, 'main']))->assertInertia(fn (Assert $page) => $page->where('workspace.connection.canWrite', false));
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
