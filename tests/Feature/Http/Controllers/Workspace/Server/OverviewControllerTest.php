<?php

namespace Tests\Feature\Http\Controllers\Workspace\Server;

use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
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
 * Server-Übersicht (W-01): im Arbeitsbildschirm mit Navigator, ansehen darf jede Person mit Zugang; ob sie ändern
 * darf, erfährt die Oberfläche samt Grund.
 */
#[Group('target-databases')]
class OverviewControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/uebersicht-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
            'phpdbadmin.connections.connect_timeout' => 2,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_sqlite_overview_shows_file_page_size_and_journal(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->get(route('workspace.server', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/server/Overview')
                ->where('server.section', 'overview')
                ->where('server.hasServer', false)
                ->where('server.canChange', true)
                ->where('workspace.schemas', ['main'])
                ->where('workspace.schema', null)
                ->where('overview.uptime', null)
                ->where('overview.facts.0', ['key' => 'file', 'value' => $connection->sqlite_path, 'kind' => 'text'])
                ->where('overview.databases.0.name', 'main')
                ->missing('workspace.connection.password'));
    }

    #[DataProvider('serverDrivers')]
    public function test_server_overview_shows_version_uptime_and_test_database(DatabaseDriver $driver): void
    {
        $connection = $this->savedConnection($this->scratchSettings($driver, $this->folder), $this->user);

        $this->actingAs($this->user)->get(route('workspace.server', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/server/Overview')
                ->where('server.hasServer', true)
                ->where('overview.uptime', fn (int $uptime): bool => $uptime > 0)
                ->where('overview.databases', fn ($databases): bool => in_array($this->targetTestDatabase(), array_column($databases->all(), 'name'), true))
                ->missing('workspace.connection.password'));
    }

    public function test_read_only_connection_says_changes_are_blocked(): void
    {
        $connection = $this->sqliteConnection(attributes: ['read_only' => true, 'environment' => 'production']);

        $this->actingAs($this->user)->get(route('workspace.server', $connection))
            ->assertInertia(fn (Assert $page) => $page
                ->where('server.canChange', false)
                ->where('server.blocked', 'read_only')
                ->where('server.confirmName', $connection->name));
    }

    public function test_member_with_read_permission_sees_overview_but_may_not_change(): void
    {
        $connection = $this->sqliteConnection(owner: User::factory()->create(), attributes: ['shared' => true]);
        $connection->members()->attach($this->user, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($this->user)->get(route('workspace.server', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('server.canChange', false)
                ->where('server.blocked', 'not_manager'));
    }

    public function test_connection_of_other_account_is_404(): void
    {
        $foreign = $this->sqliteConnection(owner: User::factory()->create());

        $this->actingAs($this->user)->get(route('workspace.server', $foreign))->assertNotFound();
    }

    public function test_unreachable_server_shows_problem_instead_of_error_page(): void
    {
        $connection = DatabaseConnection::factory()->for($this->user, 'owner')->create(['host' => '127.0.0.1', 'port' => 1]);

        $this->actingAs($this->user)->get(route('workspace.server', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connect/Unavailable')
                ->where('problem.kind', 'host_unreachable'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sqliteConnection(?User $owner = null, array $attributes = []): DatabaseConnection
    {
        $file = $this->folder.'/uebersicht-'.uniqid().'.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table notes (id integer primary key, body text)');

        return DatabaseConnection::factory()->for($owner ?? $this->user, 'owner')->sqlite($file)->create($attributes);
    }
}
