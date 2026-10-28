<?php

namespace Tests\Feature\Feasibility;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gemeinsame Basis der Feasibility-Proben (02-feasibility, F-1 bis F-8).
 *
 * Jede Probe läuft gegen alle vier Treiber: MySQL 8.4, MariaDB 10.11, PostgreSQL 18 und SQLite.
 * Die Server sind die lokalen Herd-Dienste; ist einer nicht erreichbar, wird die Probe für diesen
 * Treiber ausdrücklich übersprungen (nie still bestanden). Befunde, die in die Doku gehen, schreibt
 * jede Probe zusätzlich nach storage/framework/testing/feasibility/<datei>.json.
 */
abstract class ProbeTestCase extends TestCase
{
    protected const PROBE_DATABASE = 'phpdbadmin_probe';

    /** @var list<string> */
    private array $sqliteFiles = [];

    /**
     * @return array<string, array{0: string}>
     */
    public static function drivers(): array
    {
        return [
            'mysql' => ['mysql'],
            'mariadb' => ['mariadb'],
            'pgsql' => ['pgsql'],
            'sqlite' => ['sqlite'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function serverDrivers(): array
    {
        return array_diff_key(static::drivers(), ['sqlite' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->sqliteFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * Baut eine leere Probe-Datenbank für den Treiber und gibt die Verbindung zurück.
     */
    protected function freshConnection(string $driver): Connection
    {
        $connection = $this->connection($driver);

        $schema = $connection->getSchemaBuilder();
        $schema->dropAllViews();
        $schema->dropAllTables();

        if ($driver === 'pgsql') {
            $schema->dropAllTypes();
            $connection->statement('DROP FUNCTION IF EXISTS probe_orders_note() CASCADE');
        }

        return $connection;
    }

    /**
     * Verbindung zur Probe-Datenbank, zur Laufzeit gebaut wie später jede Zielverbindung.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function connection(string $driver, array $overrides = []): Connection
    {
        $config = array_replace($this->config($driver), $overrides);

        if ($driver !== 'sqlite') {
            $this->ensureServerReachable($driver, $config);
            $this->ensureProbeDatabaseExists($driver, $config);
        }

        /** @var Connection $connection */
        $connection = DB::build($config);

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(string $driver): array
    {
        return match ($driver) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => (int) (getenv('PROBE_MYSQL_PORT') ?: 3307),
                'database' => self::PROBE_DATABASE,
                'username' => getenv('PROBE_MYSQL_USERNAME') ?: 'root',
                'password' => getenv('PROBE_MYSQL_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'mariadb' => [
                'driver' => 'mariadb',
                'host' => '127.0.0.1',
                'port' => (int) (getenv('PROBE_MARIADB_PORT') ?: 3306),
                'database' => self::PROBE_DATABASE,
                'username' => getenv('PROBE_MARIADB_USERNAME') ?: 'root',
                'password' => getenv('PROBE_MARIADB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => (int) (getenv('PROBE_PGSQL_PORT') ?: 5432),
                'database' => self::PROBE_DATABASE,
                'username' => getenv('PROBE_PGSQL_USERNAME') ?: 'root',
                'password' => getenv('PROBE_PGSQL_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $this->sqliteFile(),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }

    protected function sqliteFile(): string
    {
        $file = storage_path('framework/testing/probe-'.getmypid().'-'.count($this->sqliteFiles).'.sqlite');
        @mkdir(dirname($file), 0755, true);
        touch($file);

        return $this->sqliteFiles[] = $file;
    }

    /**
     * Hält einen Befund für den Feasibility-Bericht fest (je Datei und Treiber).
     */
    protected function recordFinding(string $file, string $driver, mixed $data): void
    {
        $path = storage_path("framework/testing/feasibility/{$file}.json");
        @mkdir(dirname($path), 0755, true);

        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all[$driver] = $data;

        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Misst die Dauer eines Aufrufs in Millisekunden.
     *
     * @return array{0: mixed, 1: float}
     */
    protected function measure(callable $callback): array
    {
        $start = hrtime(true);
        $result = $callback();

        return [$result, round((hrtime(true) - $start) / 1e6, 1)];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ensureServerReachable(string $driver, array $config): void
    {
        $socket = @fsockopen((string) $config['host'], (int) $config['port'], $errno, $error, 0.5);

        if ($socket === false) {
            $this->markTestSkipped("{$driver} auf {$config['host']}:{$config['port']} nicht erreichbar — Probe übersprungen, nicht bestanden");
        }

        fclose($socket);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ensureProbeDatabaseExists(string $driver, array $config): void
    {
        /** @var Connection $server */
        $server = DB::build(array_replace($config, ['database' => $driver === 'pgsql' ? 'postgres' : 'information_schema']));

        if ($driver === 'pgsql') {
            $exists = $server->selectOne('SELECT 1 AS found FROM pg_database WHERE datname = ?', [self::PROBE_DATABASE]);

            if ($exists === null) {
                $server->statement('CREATE DATABASE '.self::PROBE_DATABASE);
            }
        } else {
            $server->statement('CREATE DATABASE IF NOT EXISTS '.self::PROBE_DATABASE.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        $server->disconnect();
    }
}
