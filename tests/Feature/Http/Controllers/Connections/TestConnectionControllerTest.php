<?php

namespace Tests\Feature\Http\Controllers\Connections;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionNotAllowed;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Connections\KnownHosts;
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
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'app', 'password' => 'Geheim-Test-6', 'all_databases' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('problem.kind', 'host_unreachable')
            ->assertJsonPath('problem.title', 'Server nicht erreichbar');
        $this->assertStringNotContainsString('Geheim-Test-6', (string) $response->getContent());
    }

    public function test_reports_problem_in_the_language_of_the_account(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'en']))
            ->postJson(route('connections.test'), ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'app', 'all_databases' => true])
            ->assertOk()
            ->assertJsonPath('problem.title', 'Server unreachable')
            ->assertJsonPath('problem.explanation', 'No database server answers at this address and port. Check host and port and whether the service is running.');
    }

    public function test_rejects_host_outside_allowed_list_at_the_field(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('connections.test'), ['driver' => 'pgsql', 'host' => '10.255.255.1', 'username' => 'x', 'database' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['host' => 'Dieser Host ist in dieser Installation nicht erlaubt.']);
    }

    public function test_database_name_cannot_smuggle_a_second_host_into_the_dsn(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('connections.test'), ['driver' => 'pgsql', 'host' => '127.0.0.1', 'username' => 'x', 'database' => "postgres' host='10.0.0.5"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['database' => 'Datenbanknamen mit ; \' " = \\ oder Steuerzeichen nimmt phpdbadmin nicht an.']);
    }

    public function test_stored_connection_with_such_a_name_never_connects(): void
    {
        $connection = DatabaseConnection::factory()->for(User::factory()->create(), 'owner')->create([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'shop;host=10.0.0.5;port=3306', 'username' => 'root', 'password' => '',
        ]);

        try {
            app(ConnectionFactory::class)->make(ConnectionSettings::fromConnection($connection));
            $this->fail('Verbunden trotz eingeschleustem Host');
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame(ConnectionProblemKind::DatabaseNotAllowed, $exception->problem->kind);
        }
    }

    public function test_a_name_that_changes_the_dsn_is_refused_before_any_tunnel_opens(): void
    {
        $connection = DatabaseConnection::factory()->for(User::factory()->create(), 'owner')->create([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'shop;host=10.0.0.5', 'username' => 'root', 'password' => '',
        ]);
        $connection->forceFill(['ssh_host' => '127.0.0.1', 'ssh_port' => 1, 'ssh_username' => 'deploy', 'ssh_key' => 'kein-echter-schluessel'])->save();
        $this->assertTrue($connection->usesSsh());

        try {
            app(ConnectionFactory::class)->make(ConnectionSettings::fromConnection($connection));
            $this->fail('Tunnel geöffnet trotz eingeschleustem Host');
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame(ConnectionProblemKind::DatabaseNotAllowed, $exception->problem->kind);
        }
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

    public function test_ssh_tunnel_problem_is_named_and_key_never_echoed(): void
    {
        if (KnownHosts::executable('ssh') === null) {
            $this->markTestSkipped('OpenSSH fehlt — übersprungen, nicht bestanden');
        }

        config([
            'phpdbadmin.ssh.known_hosts' => $this->folder.'/known_hosts',
            'phpdbadmin.ssh.temporary' => $this->folder.'/tmp',
            'phpdbadmin.ssh.timeout' => 3,
        ]);
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nGeheimer-Schluessel-Inhalt\n-----END OPENSSH PRIVATE KEY-----";

        $response = $this->actingAs(User::factory()->create())->postJson(route('connections.test'), [
            'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '3306', 'username' => 'app', 'all_databases' => true,
            'ssh' => true, 'ssh_host' => '127.0.0.1', 'ssh_port' => '1', 'ssh_username' => 'deploy',
            'ssh_key' => $key, 'ssh_passphrase' => 'Geheim-Phrase-9',
        ])->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('steps.1.key', 'tunnel')
            ->assertJsonPath('steps.1.state', 'failed');

        $this->assertContains($response->json('problem.kind'), ['ssh_key_invalid', 'ssh_unreachable']);
        $this->assertStringNotContainsString('Geheimer-Schluessel-Inhalt', (string) $response->getContent());
        $this->assertStringNotContainsString('Geheim-Phrase-9', (string) $response->getContent());
    }
}
