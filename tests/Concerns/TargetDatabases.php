<?php

namespace Tests\Concerns;

use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;

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

    protected function skipUnlessReachable(DatabaseDriver $driver, string $host, int $port): void
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.5);

        if ($socket === false) {
            $this->markTestSkipped($driver->label().' auf '.$host.':'.$port.' nicht erreichbar — übersprungen, nicht bestanden');
        }

        fclose($socket);
    }
}
