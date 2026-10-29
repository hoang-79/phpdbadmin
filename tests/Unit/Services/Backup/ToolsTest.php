<?php

namespace Tests\Unit\Services\Backup;

use App\Enums\DatabaseDriver;
use App\Services\Backup\Tools;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Versionsprüfung der Server-Werkzeuge (E-03): Version und Herkunft aus `--version`, passt das Werkzeug zum Server?
 */
class ToolsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function versions(): array
    {
        return [
            'mysqldump 8.4' => ['mysqldump  Ver 8.4.11 for macos15 on arm64 (MySQL Community Server - GPL)', '8.4.11', 'mysql'],
            'mysql 5.7' => ['mysql  Ver 14.14 Distrib 5.7.44, for Linux (x86_64) using  EditLine wrapper', '5.7.44', 'mysql'],
            'mariadb-dump' => ['mariadb-dump  Ver 10.19 Distrib 10.11.6-MariaDB, for osx10.13 (arm64)', '10.11.6', 'mariadb'],
            'mariadb 11' => ['mariadb from 11.4.2-MariaDB, client 15.2 for Linux (x86_64)', '11.4.2', 'mariadb'],
            'pg_dump' => ['pg_dump (PostgreSQL) 18.0', '18.0', 'postgres'],
            'pg_dump Homebrew' => ['pg_dump (PostgreSQL) 16.4 (Homebrew)', '16.4', 'postgres'],
            'sqlite3' => ['3.45.3 2024-04-15 13:34:05 8653b758870e6ef0c98d46b3ace27849054af85da891eb121e9aaa537f1ealt1 (64-bit)', '3.45.3', 'sqlite'],
            'unbekannt' => ['command not found', null, null],
        ];
    }

    #[DataProvider('versions')]
    public function test_reads_version_and_origin(string $output, ?string $version, ?string $flavor): void
    {
        $this->assertSame([$version, $flavor], Tools::parseVersion($output));
    }

    /**
     * @return array<string, array{0: DatabaseDriver, 1: string|null, 2: string|null, 3: string, 4: string|null}>
     */
    public static function pairs(): array
    {
        return [
            'pg_dump gleich alt' => [DatabaseDriver::Postgres, '18.0', 'postgres', '18.0', null],
            'pg_dump neuer' => [DatabaseDriver::Postgres, '18.0', 'postgres', '16.4', null],
            'pg_dump älter' => [DatabaseDriver::Postgres, '16.4', 'postgres', '18.0', 'pg_dump :version ist älter'],
            'mysqldump zu MySQL' => [DatabaseDriver::MySql, '8.4.11', 'mysql', '8.4.11', null],
            'mysqldump älter' => [DatabaseDriver::MySql, '5.7.44', 'mysql', '8.4.11', 'ist älter als der Server (MySQL'],
            'mariadb-dump zu MySQL' => [DatabaseDriver::MySql, '10.11.6', 'mariadb', '8.4.11', 'gehört zu MariaDB'],
            'mysqldump zu MariaDB' => [DatabaseDriver::MariaDb, '8.4.11', 'mysql', '10.11.6', 'gehört zu MySQL'],
            'mariadb-dump zu MariaDB' => [DatabaseDriver::MariaDb, '10.11.6', 'mariadb', '11.4.2', null],
            'Version unlesbar' => [DatabaseDriver::Postgres, null, null, '18.0', 'liess sich nicht lesen'],
        ];
    }

    #[DataProvider('pairs')]
    public function test_checks_that_the_tool_fits_the_server(DatabaseDriver $driver, ?string $version, ?string $flavor, string $server, ?string $problem): void
    {
        $mismatch = Tools::mismatch($driver, $version, $flavor, $server);

        if ($problem === null) {
            $this->assertNull($mismatch);
        } else {
            $this->assertStringContainsString($problem, (string) $mismatch);
        }
    }
}
