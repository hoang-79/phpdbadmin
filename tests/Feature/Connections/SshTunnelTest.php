<?php

namespace Tests\Feature\Connections;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionNotAllowed;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Connections\ConnectionTester;
use App\Connections\KnownHosts;
use App\Connections\SshSettings;
use App\Connections\SshTunnels;
use App\Connections\TargetConnections;
use App\Connections\TunnelFailed;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * SSH-Tunnel (V-08). Gegen einen echten SSH-Dienst nur, wenn einer erreichbar ist — sonst sichtbar übersprungen.
 * Einstellbar über die Umgebung:
 *
 * - PHPDBADMIN_TEST_SSH_HOST, _PORT, _USER, _KEY (Datei mit dem privaten Schlüssel; Vorgabe 127.0.0.1:22, das
 *   eigene Konto, ohne Schlüssel → übersprungen)
 * - PHPDBADMIN_TEST_SSH_TARGET_DRIVER, _TARGET_HOST, _TARGET_PORT, _TARGET_USER: die Datenbank aus Sicht des
 *   SSH-Servers (Vorgabe MariaDB auf 127.0.0.1:3306, Konto root ohne Passwort wie in Herd)
 *
 * Was ohne SSH-Dienst geht (Passphrase, nicht erreichbarer Server, nicht erlaubter Host, Aufräumen), läuft immer.
 */
#[Group('target-databases')]
class SshTunnelTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/ssh-'.getmypid());
        @mkdir($this->folder.'/keys', 0700, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1', 'localhost', (string) (getenv('PHPDBADMIN_TEST_SSH_HOST') ?: '127.0.0.1')],
            'phpdbadmin.connections.connect_timeout' => 3,
            'phpdbadmin.ssh.known_hosts' => $this->folder.'/known_hosts',
            'phpdbadmin.ssh.temporary' => $this->folder.'/tmp',
            'phpdbadmin.ssh.key_paths' => [$this->folder.'/keys'],
            'phpdbadmin.ssh.timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        app(SshTunnels::class)->closeAll();
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_tunnel_reaches_the_database_and_shows_the_host_key(): void
    {
        $settings = $this->tunnelSettings();

        $result = app(ConnectionTester::class)->test($settings);

        $this->assertTrue($result->ok, (string) $result->problem?->serverMessage);
        $this->assertSame(['allowed', 'tunnel', 'login', 'account'], array_column($result->steps, 'key'));
        $this->assertMatchesRegularExpression('/^[A-Z0-9-]+ SHA256:/', (string) $result->hostKey);
        $this->assertSame(0, app(SshTunnels::class)->openCount(), 'nach dem Test läuft kein Tunnel mehr');
        $this->assertSame([], glob($this->folder.'/tmp/*') ?: [], 'keine Schlüsseldatei bleibt liegen');
    }

    public function test_saved_connection_opens_through_the_tunnel_and_closes_it_at_the_end(): void
    {
        $settings = $this->tunnelSettings();
        $connection = DatabaseConnection::factory()->for(User::factory()->create(), 'owner')->create([
            'driver' => $settings->driver,
            'host' => $settings->host,
            'port' => $settings->port,
            'database' => null,
            'username' => $settings->username,
            'password' => $settings->password,
            'ssh_host' => $settings->ssh?->host,
            'ssh_port' => $settings->ssh?->port,
            'ssh_username' => $settings->ssh?->username,
            'ssh_key' => $settings->ssh?->privateKey,
        ]);

        $targets = app(TargetConnections::class);
        $this->assertSame(1, (int) $targets->open($connection)->selectOne('select 1 as one')?->one);
        $targets->withWritable($connection, fn ($writable) => $writable->selectOne('select 1'));
        $this->assertSame(1, app(SshTunnels::class)->openCount(), 'beide Verbindungen teilen einen Tunnel');

        $targets->releaseAll();
        $this->assertSame(0, app(SshTunnels::class)->openCount());
    }

    public function test_database_unreachable_behind_the_tunnel_is_named(): void
    {
        $settings = $this->tunnelSettings();
        $closed = new ConnectionSettings($settings->driver, '127.0.0.1', $this->closedPort(), $settings->database, $settings->username, $settings->password, ssh: $settings->ssh);

        $result = app(ConnectionTester::class)->test($closed);

        $this->assertSame(ConnectionProblemKind::SshForwardFailed, $result->problem?->kind, (string) $result->problem?->serverMessage);
        $this->assertSame(['tunnel' => 'ok', 'login' => 'failed'], array_slice(array_column($result->steps, 'state', 'key'), 1, 2));
        $this->assertSame(0, app(SshTunnels::class)->openCount());
    }

    public function test_rejected_key_is_named(): void
    {
        $settings = $this->tunnelSettings();
        $stranger = $this->generateKey('fremd');

        $result = app(ConnectionTester::class)->test($settings->withSsh($settings->ssh?->withKey($stranger, null)));

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionProblemKind::SshKeyRejected, $result->problem?->kind);
        $this->assertSame(['tunnel' => 'failed', 'login' => 'skipped', 'account' => 'skipped'], array_slice(array_column($result->steps, 'state', 'key'), 1));
    }

    public function test_changed_host_key_is_refused(): void
    {
        $settings = $this->tunnelSettings();
        $ssh = $settings->ssh;
        $this->assertNotNull($ssh);
        // Ein anderer Schlüssel steht schon unter dem Namen des Servers — so, als wäre er neu aufgesetzt worden.
        $this->generateKey('anderer-host');
        $public = trim((string) file_get_contents($this->folder.'/keys/anderer-host.pub'));
        file_put_contents($this->folder.'/known_hosts', KnownHosts::name($ssh->host, $ssh->port()).' '.$public."\n");

        $result = app(ConnectionTester::class)->test($settings);

        $this->assertSame(ConnectionProblemKind::SshHostKeyChanged, $result->problem?->kind);
    }

    public function test_wrong_passphrase_is_named_before_connecting(): void
    {
        $key = $this->generateKey('geschuetzt', 'Richtige-Phrase');

        $this->expectTunnelProblem(ConnectionProblemKind::SshKeyInvalid, new SshSettings('127.0.0.1', $this->closedPort(), 'niemand', $key, passphrase: 'Falsche-Phrase'));
    }

    public function test_right_passphrase_unlocks_the_key_and_the_next_step_is_the_server(): void
    {
        $key = $this->generateKey('geschuetzt', 'Richtige-Phrase');

        // Der Schlüssel ist entsperrt: Gescheitert wird erst am Server, der hier nicht antwortet.
        $this->expectTunnelProblem(ConnectionProblemKind::SshUnreachable, new SshSettings('127.0.0.1', $this->closedPort(), 'niemand', $key, passphrase: 'Richtige-Phrase'));
        $this->assertSame([], glob($this->folder.'/tmp/*') ?: [], 'die entsperrte Kopie ist wieder weg');
    }

    public function test_key_file_outside_allowed_folders_is_refused(): void
    {
        $this->generateKey('erlaubt');
        @mkdir($this->folder.'/draussen');
        copy($this->folder.'/keys/erlaubt', $this->folder.'/draussen/id');

        try {
            app(ConnectionFactory::class)->tunnel($this->settings(new SshSettings('127.0.0.1', $this->closedPort(), 'niemand', keyPath: $this->folder.'/draussen/id')));
            $this->fail('Schlüssel ausserhalb wurde verwendet');
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame(ConnectionProblemKind::FileNotAllowed, $exception->problem->kind);
        }
    }

    public function test_ssh_host_outside_the_list_is_refused_without_starting_ssh(): void
    {
        config(['phpdbadmin.connections.allowed_hosts' => ['127.0.0.1']]);

        try {
            app(ConnectionFactory::class)->tunnel($this->settings(new SshSettings('10.11.12.13', 22, 'niemand', $this->generateKey('k'))));
            $this->fail('SSH-Server ausserhalb der Liste wurde verwendet');
        } catch (ConnectionNotAllowed $exception) {
            $this->assertNotInstanceOf(TunnelFailed::class, $exception);
            $this->assertSame(ConnectionProblemKind::HostNotAllowed, $exception->problem->kind);
        }
    }

    public function test_target_behind_the_tunnel_follows_the_same_list(): void
    {
        config(['phpdbadmin.connections.allowed_hosts' => ['127.0.0.1']]);
        $settings = new ConnectionSettings(DatabaseDriver::MariaDb, '10.0.0.5', 3306, null, 'root', ssh: new SshSettings('127.0.0.1', 22, 'niemand', $this->generateKey('k')));

        try {
            app(ConnectionFactory::class)->tunnel($settings);
            $this->fail('Ziel ausserhalb der Liste wurde weitergeleitet');
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame(ConnectionProblemKind::HostNotAllowed, $exception->problem->kind);
        }
    }

    private function expectTunnelProblem(ConnectionProblemKind $kind, SshSettings $ssh): void
    {
        $this->skipWithoutOpenSsh();

        try {
            app(ConnectionFactory::class)->tunnel($this->settings($ssh));
            $this->fail('Tunnel kam zustande');
        } catch (TunnelFailed $exception) {
            $this->assertSame($kind, $exception->problem->kind, (string) $exception->problem->serverMessage);
        }
    }

    private function settings(SshSettings $ssh): ConnectionSettings
    {
        return new ConnectionSettings(DatabaseDriver::MariaDb, '127.0.0.1', 3306, null, 'root', ssh: $ssh);
    }

    /**
     * Angaben für den echten Dienst — oder sichtbar übersprungen.
     */
    private function tunnelSettings(): ConnectionSettings
    {
        $this->skipWithoutOpenSsh();

        $host = (string) (getenv('PHPDBADMIN_TEST_SSH_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('PHPDBADMIN_TEST_SSH_PORT') ?: 22);
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.5);

        if ($socket === false) {
            $this->markTestSkipped('Kein SSH-Dienst auf '.$host.':'.$port.' erreichbar — übersprungen, nicht bestanden (PHPDBADMIN_TEST_SSH_HOST, _PORT, _USER, _KEY)');
        }

        fclose($socket);
        $keyFile = (string) getenv('PHPDBADMIN_TEST_SSH_KEY');

        if ($keyFile === '' || ! is_readable($keyFile)) {
            $this->markTestSkipped('SSH-Dienst auf '.$host.':'.$port.', aber kein Schlüssel in PHPDBADMIN_TEST_SSH_KEY — übersprungen, nicht bestanden');
        }

        $driver = DatabaseDriver::from((string) (getenv('PHPDBADMIN_TEST_SSH_TARGET_DRIVER') ?: DatabaseDriver::MariaDb->value));

        return new ConnectionSettings(
            driver: $driver,
            host: (string) (getenv('PHPDBADMIN_TEST_SSH_TARGET_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('PHPDBADMIN_TEST_SSH_TARGET_PORT') ?: $driver->defaultPort()),
            database: $driver === DatabaseDriver::Postgres ? 'postgres' : null,
            username: (string) (getenv('PHPDBADMIN_TEST_SSH_TARGET_USER') ?: 'root'),
            password: getenv('PHPDBADMIN_TEST_SSH_TARGET_PASSWORD') ?: null,
            ssh: new SshSettings($host, $port, (string) (getenv('PHPDBADMIN_TEST_SSH_USER') ?: get_current_user()), (string) file_get_contents($keyFile)),
        );
    }

    private function skipWithoutOpenSsh(): void
    {
        if (KnownHosts::executable('ssh') === null || KnownHosts::executable('ssh-keygen') === null) {
            $this->markTestSkipped('OpenSSH (ssh, ssh-keygen) fehlt — übersprungen, nicht bestanden');
        }
    }

    /** Neuer Schlüssel im erlaubten Ordner; gibt den privaten Schlüssel zurück. */
    private function generateKey(string $name, string $passphrase = ''): string
    {
        $this->skipWithoutOpenSsh();
        $file = $this->folder.'/keys/'.$name;
        @unlink($file);
        @unlink($file.'.pub');
        (new Process([(string) KnownHosts::executable('ssh-keygen'), '-q', '-t', 'ed25519', '-N', $passphrase, '-C', 'phpdbadmin-test', '-f', $file]))->mustRun();

        return (string) file_get_contents($file);
    }

    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
