<?php

namespace Tests\Feature\Connections;

use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Connections\ConnectionTester;
use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * «Verbindung testen» gegen die echten Server (V-01, V-02): Erfolg mit Version und jede Fehlerart in Klartext,
 * nie mit dem Passwort.
 */
#[Group('target-databases')]
class ConnectionTesterTest extends TestCase
{
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/tester-'.getmypid());
        @mkdir($this->folder, 0777, true);

        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
            'phpdbadmin.connections.connect_timeout' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    #[DataProvider('serverDrivers')]
    public function test_connects_and_reports_server_version(DatabaseDriver $driver): void
    {
        $result = $this->tester()->test($this->serverSettings($driver));

        $this->assertTrue($result->ok, (string) $result->problem?->serverMessage);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', (string) $result->version);
        $this->assertSame(['allowed', 'reachable', 'login', 'account'], array_column($result->steps, 'key'));
    }

    #[DataProvider('serverDrivers')]
    public function test_unreachable_port_is_reported_before_login(DatabaseDriver $driver): void
    {
        $this->serverSettings($driver);
        $settings = new ConnectionSettings($driver, '127.0.0.1', 1, 'x', 'root');

        $result = $this->tester()->test($settings);

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionProblemKind::HostUnreachable, $result->problem?->kind);
        $this->assertSame(['ok', 'failed', 'skipped', 'skipped'], array_column($result->steps, 'state'));
    }

    #[DataProvider('serverDrivers')]
    public function test_wrong_account_is_access_denied_without_password_in_message(DatabaseDriver $driver): void
    {
        $settings = $this->serverSettings($driver, ['username' => 'phpdbadmin_gibt_es_nicht', 'password' => 'Geheim-Test-4711']);

        $result = $this->tester()->test($settings);

        $this->assertSame(ConnectionProblemKind::AccessDenied, $result->problem?->kind, (string) $result->problem?->serverMessage);
        $this->assertStringNotContainsString('Geheim-Test-4711', (string) json_encode($result->toArray()));
        $this->assertSame('Zugang verweigert'.($driver->isServer() && $driver !== DatabaseDriver::Postgres ? ' (1045)' : ' (08006)'), $result->problem->title());
    }

    #[DataProvider('serverDrivers')]
    public function test_missing_database_is_reported(DatabaseDriver $driver): void
    {
        $result = $this->tester()->test($this->serverSettings($driver, ['database' => 'phpdbadmin_gibt_es_nicht']));

        $this->assertSame(ConnectionProblemKind::DatabaseMissing, $result->problem?->kind, (string) $result->problem?->serverMessage);
    }

    public function test_required_ssl_on_server_without_ssl_is_reported(): void
    {
        $settings = $this->serverSettings(DatabaseDriver::Postgres, ['sslMode' => SslMode::Require]);

        $result = $this->tester()->test($settings);

        if ($result->ok) {
            $this->markTestSkipped('Der lokale PostgreSQL-Server spricht SSL — kein Fehler zu erzwingen.');
        }

        $this->assertSame(ConnectionProblemKind::Ssl, $result->problem?->kind, (string) $result->problem?->serverMessage);
    }

    public function test_host_outside_allowed_list_stops_at_first_step(): void
    {
        $result = $this->tester()->test(new ConnectionSettings(DatabaseDriver::MySql, '10.255.255.1', 3306, null, 'root'));

        $this->assertSame(ConnectionProblemKind::HostNotAllowed, $result->problem?->kind);
        $this->assertSame(['failed', 'skipped', 'skipped', 'skipped'], array_column($result->steps, 'state'));
    }

    public function test_opens_sqlite_file_and_reports_library_version(): void
    {
        $file = $this->folder.'/notizen.sqlite';
        (new \PDO('sqlite:'.$file))->exec('create table notes (id integer primary key)');

        $result = $this->tester()->test(new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $file));

        $this->assertTrue($result->ok, (string) $result->problem?->serverMessage);
        $this->assertSame(['allowed', 'login'], array_column($result->steps, 'key'));
    }

    public function test_file_that_is_no_sqlite_database_is_reported(): void
    {
        $file = $this->folder.'/text.sqlite';
        file_put_contents($file, str_repeat('Das ist keine Datenbank. ', 100));

        $result = $this->tester()->test(new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $file));

        $this->assertSame(ConnectionProblemKind::FileNotDatabase, $result->problem?->kind, (string) $result->problem?->serverMessage);
    }

    private function tester(): ConnectionTester
    {
        return app(ConnectionTester::class);
    }
}
