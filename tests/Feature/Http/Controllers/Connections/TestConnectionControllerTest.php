<?php

namespace Tests\Feature\Http\Controllers\Connections;

use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Verbindung testen» als Endpunkt (V-02): Ergebnis als JSON, Prüffehler am Feld, nie ein Passwort.
 */
class TestConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/test-endpoint-'.getmypid());
        @mkdir($this->folder, 0777, true);
        (new \PDO('sqlite:'.$this->folder.'/notizen.sqlite'))->exec('create table notes (id integer primary key)');

        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
            'phpdbadmin.connections.connect_timeout' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_returns_steps_and_version_for_sqlite_file(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('connections.test'), ['driver' => 'sqlite', 'sqlite_path' => $this->folder.'/notizen.sqlite'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('steps.0.label', 'Datei erlaubt')
            ->assertJsonPath('steps.1.state', 'ok');
    }

    public function test_reports_unreachable_server_without_echoing_password(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson(route('connections.test'), [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'app', 'password' => 'Geheim-Test-6',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('problem.kind', 'host_unreachable')
            ->assertJsonPath('problem.title', 'Server nicht erreichbar');
        $this->assertStringNotContainsString('Geheim-Test-6', (string) $response->getContent());
    }

    public function test_rejects_host_outside_allowed_list_at_the_field(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('connections.test'), ['driver' => 'pgsql', 'host' => '10.255.255.1', 'username' => 'x', 'database' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['host' => 'Dieser Host ist in dieser Installation nicht erlaubt.']);
    }

    public function test_saved_connection_records_version_after_successful_test(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($user, 'owner')->sqlite($this->folder.'/notizen.sqlite')->create();

        $this->actingAs($user)
            ->postJson(route('connections.test'), [
                'driver' => 'sqlite', 'sqlite_path' => $this->folder.'/notizen.sqlite', 'connection' => $connection->id,
            ])
            ->assertJsonPath('ok', true);

        $this->assertNotNull($connection->fresh()?->server_version);
        $this->assertNotNull($connection->fresh()?->last_tested_at);
    }

    public function test_foreign_connection_id_is_ignored(): void
    {
        $foreign = DatabaseConnection::factory()->sqlite($this->folder.'/notizen.sqlite')->create();

        $this->actingAs(User::factory()->create())
            ->postJson(route('connections.test'), [
                'driver' => 'sqlite', 'sqlite_path' => $this->folder.'/notizen.sqlite', 'connection' => $foreign->id,
            ])
            ->assertJsonPath('ok', true);

        $this->assertNull($foreign->fresh()?->last_tested_at);
    }
}
