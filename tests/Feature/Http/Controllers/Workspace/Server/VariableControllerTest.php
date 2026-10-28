<?php

namespace Tests\Feature\Http\Controllers\Workspace\Server;

use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Variablen und Status (W-03): nur lesen; gesucht wird in der Oberfläche.
 */
#[Group('target-databases')]
class VariableControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/variablen-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_sqlite_shows_readable_pragmas_and_compile_options(): void
    {
        $user = User::factory()->create();
        $file = $this->folder.'/variablen.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table notes (id integer primary key)');
        $connection = DatabaseConnection::factory()->for($user, 'owner')->sqlite($file)->create(['read_only' => true]);

        $this->actingAs($user)->get(route('workspace.server.variables', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/server/Variables')
                ->where('server.section', 'variables')
                ->where('variables', fn ($variables): bool => in_array(['name' => 'page_size', 'value' => '4096', 'description' => null], $variables->all(), true))
                ->where('status', fn ($status): bool => in_array('THREADSAFE', array_column($status->all(), 'name'), true)));
    }

    public function test_postgres_shows_settings_with_description_and_database_statistics(): void
    {
        $user = User::factory()->create();
        $connection = $this->savedConnection($this->scratchSettings(DatabaseDriver::Postgres, $this->folder), $user);

        $this->actingAs($user)->get(route('workspace.server.variables', $connection))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('variables', fn ($variables): bool => in_array('max_connections', array_column($variables->all(), 'name'), true)
                    && array_column($variables->all(), 'description', 'name')['max_connections'] !== null)
                ->where('status', fn ($status): bool => in_array('pg_stat_database.xact_commit', array_column($status->all(), 'name'), true)));
    }
}
