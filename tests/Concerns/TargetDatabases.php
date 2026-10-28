<?php

namespace Tests\Concerns;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;

/**
 * Die lokalen Datenbank-Server als Ziele für Tests (B-03): MySQL 8.4 auf 3307, MariaDB 10.11 auf 3306, PostgreSQL 18
 * auf 5432 (Herd, Konto root ohne Passwort). Ist ein Server nicht erreichbar, wird der Test für diesen Treiber
 * ausdrücklich übersprungen — nie still bestanden. Ports und Konten lassen sich über PHPDBADMIN_TEST_* ändern (CI).
 */
trait TargetDatabases
{
    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function serverDrivers(): array
    {
        return [
            'MySQL' => [DatabaseDriver::MySql],
            'MariaDB' => [DatabaseDriver::MariaDb],
            'PostgreSQL' => [DatabaseDriver::Postgres],
        ];
    }

    protected function serverSettings(DatabaseDriver $driver, array $overrides = []): ConnectionSettings
    {
        $prefix = 'PHPDBADMIN_TEST_'.strtoupper($driver->value).'_';
        $port = (int) (getenv($prefix.'PORT') ?: match ($driver) {
            DatabaseDriver::MySql => 3307,
            DatabaseDriver::MariaDb => 3306,
            default => 5432,
        });

        $settings = [
            'driver' => $driver,
            'host' => getenv($prefix.'HOST') ?: '127.0.0.1',
            'port' => $port,
            'database' => $driver === DatabaseDriver::Postgres ? 'postgres' : null,
            'username' => getenv($prefix.'USERNAME') ?: 'root',
            'password' => getenv($prefix.'PASSWORD') ?: null,
            ...$overrides,
        ];

        $this->skipUnlessReachable($driver, (string) $settings['host'], (int) $settings['port']);

        return new ConnectionSettings(...$settings);
    }

    /**
     * Leere Test-Datenbank «phpdbadmin_test» auf dem Server (bzw. eine leere SQLite-Datei im Ordner $folder) —
     * wird bei Bedarf angelegt und vor jedem Test geleert.
     */
    protected function scratchSettings(DatabaseDriver $driver, string $folder): ConnectionSettings
    {
        if ($driver === DatabaseDriver::Sqlite) {
            $file = $folder.'/vertrag-'.getmypid().'.sqlite';
            @unlink($file);
            touch($file);

            return new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $file);
        }

        $server = $this->serverSettings($driver);
        $root = app(ConnectionFactory::class)->make($server);

        if ($driver === DatabaseDriver::Postgres) {
            if ($root->selectOne('select 1 as found from pg_database where datname = ?', ['phpdbadmin_test']) === null) {
                $root->statement('create database phpdbadmin_test');
            }
        } else {
            $root->statement('create database if not exists phpdbadmin_test character set utf8mb4 collate utf8mb4_unicode_ci');
        }

        app(ConnectionFactory::class)->release($root);

        $settings = new ConnectionSettings($driver, $server->host, $server->port, 'phpdbadmin_test', $server->username, $server->password);
        $this->emptyDatabase(app(ConnectionFactory::class)->make($settings), $driver);

        return $settings;
    }

    protected function emptyDatabase(Connection $connection, DatabaseDriver $driver): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->dropAllViews();
        $schema->dropAllTables();

        if ($driver === DatabaseDriver::Postgres) {
            $schema->dropAllTypes();
            $connection->statement('drop function if exists vertrag_orders_note() cascade');
        }
    }

    protected function skipUnlessReachable(DatabaseDriver $driver, string $host, int $port): void
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.5);

        if ($socket === false) {
            $this->markTestSkipped($driver->label().' auf '.$host.':'.$port.' nicht erreichbar — übersprungen, nicht bestanden');
        }

        fclose($socket);
    }
}
