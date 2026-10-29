<?php

namespace Tests\Unit\Connections;

use App\Connections\ConnectionNotAllowed;
use App\Connections\ConnectionProblemKind;
use App\Connections\HostPolicy;
use App\Enums\DatabaseDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Erlaubte Hosts und SQLite-Ordner (V-05, T-05, T-08).
 */
class HostPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/phpdbadmin-hostpolicy-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/erlaubt/unter', 0777, true);
        mkdir($this->root.'/draussen', 0777, true);
        touch($this->root.'/erlaubt/notizen.sqlite');
        touch($this->root.'/erlaubt/unter/tief.sqlite');
        touch($this->root.'/draussen/geheim.sqlite');
        touch($this->root.'/erlaubt/app.sqlite');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: string}>
     */
    public static function allowedHosts(): array
    {
        return [
            'Adresse auf der Liste' => [['127.0.0.1'], '127.0.0.1', '127.0.0.1'],
            'Adresse im Netz' => [['10.0.0.0/8'], '10.1.2.3', '10.1.2.3'],
            'Name auf der Liste' => [['localhost'], 'localhost', '127.0.0.1'],
            'Name, dessen Adressen im Netz liegen' => [['127.0.0.0/8'], 'localhost', '127.0.0.1'],
            'Gross- und Kleinschreibung' => [['127.0.0.1', 'localhost'], 'LOCALHOST', '127.0.0.1'],
            'IPv6 in Klammern' => [['::1'], '[::1]', '::1'],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('allowedHosts')]
    public function test_allowed_host_resolves_to_checked_address(array $allowed, string $host, string $address): void
    {
        $this->assertSame($address, (new HostPolicy($allowed, []))->resolveHost($host));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: ConnectionProblemKind}>
     */
    public static function refusedHosts(): array
    {
        return [
            'Adresse nicht auf der Liste' => [['127.0.0.1'], '10.0.0.5', ConnectionProblemKind::HostNotAllowed],
            'Adresse ausserhalb des Netzes' => [['10.0.0.0/8'], '192.168.1.1', ConnectionProblemKind::HostNotAllowed],
            'Name nicht auf der Liste, Adresse ausserhalb' => [['10.0.0.0/8'], 'localhost', ConnectionProblemKind::HostNotAllowed],
            'Platzhalter deckt nur Unternamen' => [['*.example.ch'], '127.0.0.1', ConnectionProblemKind::HostNotAllowed],
            'Name lässt sich nicht auflösen' => [['*.invalid'], 'gibt-es-nicht.invalid', ConnectionProblemKind::HostUnknown],
            'leer' => [['127.0.0.1'], ' ', ConnectionProblemKind::HostUnknown],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('refusedHosts')]
    public function test_refuses_host_outside_the_list(array $allowed, string $host, ConnectionProblemKind $kind): void
    {
        try {
            (new HostPolicy($allowed, []))->resolveHost($host);
            $this->fail('Host wurde erlaubt: '.$host);
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame($kind, $exception->problem->kind);
        }
    }

    public function test_sqlite_file_in_allowed_folder_resolves_to_real_path(): void
    {
        $policy = new HostPolicy([], [$this->root.'/erlaubt']);

        $this->assertSame(realpath($this->root.'/erlaubt/notizen.sqlite'), $policy->resolveSqlitePath($this->root.'/erlaubt/notizen.sqlite'));
        $this->assertSame(realpath($this->root.'/erlaubt/unter/tief.sqlite'), $policy->resolveSqlitePath($this->root.'/erlaubt/unter/tief.sqlite'));
    }

    public function test_expands_home_folder(): void
    {
        $home = getenv('HOME');
        putenv('HOME='.$this->root);

        try {
            $policy = new HostPolicy([], ['~/erlaubt']);

            $this->assertSame(realpath($this->root.'/erlaubt/notizen.sqlite'), $policy->resolveSqlitePath('~/erlaubt/notizen.sqlite'));
        } finally {
            putenv('HOME='.$home);
        }
    }

    /**
     * @return array<string, array{0: string, 1: ConnectionProblemKind}>
     */
    public static function refusedPaths(): array
    {
        return [
            'Datei ausserhalb' => ['/draussen/geheim.sqlite', ConnectionProblemKind::FileNotAllowed],
            'mit .. nach draussen' => ['/erlaubt/../draussen/geheim.sqlite', ConnectionProblemKind::FileNotAllowed],
            'Verknüpfung nach draussen' => ['/erlaubt/link.sqlite', ConnectionProblemKind::FileNotAllowed],
            'Ordner mit gleichem Anfang' => ['/erlaubt-nicht/x.sqlite', ConnectionProblemKind::FileNotAllowed],
            'Datei fehlt' => ['/erlaubt/fehlt.sqlite', ConnectionProblemKind::FileMissing],
            'Ordner statt Datei' => ['/erlaubt/unter', ConnectionProblemKind::FileMissing],
            'eigene App-Datenbank' => ['/erlaubt/app.sqlite', ConnectionProblemKind::FileNotAllowed],
        ];
    }

    #[DataProvider('refusedPaths')]
    public function test_refuses_sqlite_path_outside_allowed_folders(string $path, ConnectionProblemKind $kind): void
    {
        symlink($this->root.'/draussen/geheim.sqlite', $this->root.'/erlaubt/link.sqlite');
        mkdir($this->root.'/erlaubt-nicht');
        touch($this->root.'/erlaubt-nicht/x.sqlite');

        $policy = new HostPolicy([], [$this->root.'/erlaubt'], $this->root.'/erlaubt/app.sqlite');

        try {
            $policy->resolveSqlitePath($this->root.$path);
            $this->fail('Pfad wurde erlaubt: '.$path);
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame($kind, $exception->problem->kind);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function injectedDatabaseNames(): array
    {
        return [
            'PostgreSQL: zweiter Host' => ["postgres' host='10.0.0.5' port='5432"],
            'MySQL: zweiter Host' => ['shop;host=10.0.0.5;port=3306'],
            'Gleichheitszeichen' => ['sslmode=disable'],
            'Doppeltes Anführungszeichen' => ['a"b'],
            'Backslash' => ['a\\b'],
            'Nullbyte' => ["a\x00b"],
            'Zeilenumbruch' => ["a\nb"],
        ];
    }

    #[DataProvider('injectedDatabaseNames')]
    public function test_database_names_that_would_change_the_dsn_are_refused(string $database): void
    {
        $this->assertFalse(HostPolicy::isSafeDatabaseName($database));

        try {
            (new HostPolicy(['192.0.2.10'], []))->guardDatabase(DatabaseDriver::Postgres, '192.0.2.10', 5432, $database);
            $this->fail('Name wurde erlaubt: '.$database);
        } catch (ConnectionNotAllowed $exception) {
            $this->assertSame(ConnectionProblemKind::DatabaseNotAllowed, $exception->problem->kind);
        }
    }

    public function test_ordinary_database_names_pass(): void
    {
        foreach (['shop', 'über-daten 2026', 'kunden_alt', 'Lager.2025'] as $name) {
            $this->assertTrue(HostPolicy::isSafeDatabaseName($name), $name);
        }
    }

    public function test_the_app_database_on_a_server_is_never_a_target(): void
    {
        $policy = new HostPolicy(['127.0.0.1'], [], appServer: ['driver' => DatabaseDriver::MariaDb, 'host' => 'localhost', 'port' => 3306, 'database' => 'phpdbadmin']);

        try {
            // Über den MySQL-Treiber, mit anderer Schreibweise des Namens — derselbe Server.
            $policy->guardDatabase(DatabaseDriver::MySql, '127.0.0.1', null, 'PHPDBADMIN');
            $this->fail('App-Datenbank wurde erlaubt');
        } catch (ConnectionNotAllowed $exception) {
            $this->assertTrue($exception->problem->isAppDatabase());
        }

        // Andere Datenbank, anderer Port, anderer Treiber: erlaubt.
        $policy->guardDatabase(DatabaseDriver::MySql, '127.0.0.1', 3306, 'shop');
        $policy->guardDatabase(DatabaseDriver::MySql, '127.0.0.1', 3307, 'phpdbadmin');
        $policy->guardDatabase(DatabaseDriver::Postgres, '127.0.0.1', 3306, 'phpdbadmin');
        $this->addToAssertionCount(3);
    }
}
