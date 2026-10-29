<?php

namespace App\Services\Backup;

use App\Enums\DatabaseDriver;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Die Werkzeuge der Datenbank-Server (E-03): finden, Version lesen, Fähigkeiten prüfen. Pfade stehen in
 * `phpdbadmin.backup.tools` (per .env überschreibbar); ein blosser Name wird im PATH gesucht und danach in den
 * üblichen Ordnern (`phpdbadmin.backup.directories`) — PHP-FPM hat oft einen schmalen PATH.
 *
 * @phpstan-type ToolInfo array{name: string, path: string|null, version: string|null, flavor: 'mysql'|'mariadb'|'postgres'|'sqlite'|null}
 */
final class Tools
{
    /** @var array<string, ToolInfo> */
    private array $found = [];

    /** @var array<string, string> */
    private array $help = [];

    /**
     * Werkzeuge je Treiber: zum Sichern und zum Wiederherstellen. SQLite sichert ohne Werkzeug (VACUUM INTO).
     *
     * @return array{backup: list<string>, restore: list<string>}
     */
    public static function needed(DatabaseDriver $driver): array
    {
        return match ($driver) {
            DatabaseDriver::MySql => ['backup' => ['mysqldump'], 'restore' => ['mysql']],
            DatabaseDriver::MariaDb => ['backup' => ['mariadb-dump'], 'restore' => ['mariadb']],
            DatabaseDriver::Postgres => ['backup' => ['pg_dump'], 'restore' => ['pg_restore', 'psql']],
            DatabaseDriver::Sqlite => ['backup' => [], 'restore' => ['sqlite3']],
        };
    }

    /** Name der Einstellung in .env, mit der sich der Pfad setzen lässt. */
    public static function environmentKey(string $tool): string
    {
        return 'PHPDBADMIN_TOOL_'.strtoupper(str_replace('-', '_', $tool));
    }

    /**
     * @return ToolInfo
     */
    public function info(string $tool): array
    {
        if (isset($this->found[$tool])) {
            return $this->found[$tool];
        }

        $path = $this->locate($tool);
        $version = null;
        $flavor = null;

        if ($path !== null) {
            [$version, $flavor] = self::parseVersion($this->output([$path, '--version']));
        }

        return $this->found[$tool] = ['name' => $tool, 'path' => $path, 'version' => $version, 'flavor' => $flavor];
    }

    /**
     * Kennt das Werkzeug eine Option (laut `--help`)? Für die Schutzschalter der Clients: `mysql --commands`,
     * `mariadb --sandbox`, `psql \restrict`.
     */
    public function supports(string $tool, string $option): bool
    {
        $path = $this->info($tool)['path'];

        if ($path === null) {
            return false;
        }

        $this->help[$tool] ??= $this->output([$path, '--help']).($tool === 'psql' ? $this->output([$path, '--help=commands']) : '');

        return str_contains($this->help[$tool], $option);
    }

    /**
     * Version und Herkunft aus der Ausgabe von `--version`:
     * «mysqldump  Ver 8.4.11 for macos15 on arm64 (MySQL Community Server - GPL)»,
     * «mariadb-dump  Ver 10.19 Distrib 10.11.6-MariaDB, for osx10.13 (arm64)», «pg_dump (PostgreSQL) 18.0»,
     * «3.45.3 2024-04-15 13:34:05 …» (sqlite3).
     *
     * @return array{0: string|null, 1: 'mysql'|'mariadb'|'postgres'|'sqlite'|null}
     */
    public static function parseVersion(string $output): array
    {
        return match (true) {
            preg_match('/Distrib\s+(\d+\.\d+(?:\.\d+)?)-MariaDB/i', $output, $match) === 1 => [$match[1], 'mariadb'],
            preg_match('/from\s+(\d+\.\d+(?:\.\d+)?)-MariaDB/i', $output, $match) === 1 => [$match[1], 'mariadb'],
            preg_match('/\(PostgreSQL\)\s+(\d+(?:\.\d+)?)/', $output, $match) === 1 => [$match[1], 'postgres'],
            preg_match('/Distrib\s+(\d+\.\d+\.\d+),/', $output, $match) === 1 => [$match[1], 'mysql'],
            preg_match('/Ver\s+(\d+\.\d+\.\d+)/', $output, $match) === 1 => [$match[1], 'mysql'],
            preg_match('/^(3\.\d+\.\d+)\s/', $output, $match) === 1 => [$match[1], 'sqlite'],
            default => [null, null],
        };
    }

    /**
     * Passt das Werkzeug zum Server (Versionsprüfung, E-03)? null, wenn ja — sonst der Grund als deutscher Schlüssel
     * mit den Platzhaltern :tool, :version und :server (für TransferFailed, übersetzt bei der Ausgabe). pg_dump sichert
     * nur Server bis zur eigenen Hauptversion; mysqldump und mariadb-dump nur ihre eigene Art Server.
     */
    public static function mismatch(DatabaseDriver $driver, ?string $toolVersion, ?string $flavor, string $serverVersion): ?string
    {
        $major = fn (?string $version): int => (int) explode('.', (string) $version)[0];

        $problem = match (true) {
            $toolVersion === null => 'unreadable',
            $driver === DatabaseDriver::Postgres && $major($toolVersion) < $major($serverVersion) => 'postgres-too-old',
            $driver === DatabaseDriver::MySql && $flavor !== 'mysql' => 'not-mysql',
            $driver === DatabaseDriver::MariaDb && $flavor !== 'mariadb' => 'not-mariadb',
            $driver === DatabaseDriver::MySql && $major($toolVersion) < $major($serverVersion) => 'mysql-too-old',
            default => null,
        };

        return $problem === null ? null : self::mismatchText($problem);
    }

    private static function mismatchText(string $problem): string
    {
        return match ($problem) {
            'unreadable' => __('Die Version von :tool liess sich nicht lesen.', locale: 'de'),
            'postgres-too-old' => __('pg_dump :version ist älter als der Server (PostgreSQL :server) und kann ihn nicht sichern. Installieren Sie die Werkzeuge in der Version des Servers oder neuer.', locale: 'de'),
            'not-mysql' => __(':tool gehört zu MariaDB, der Server ist MySQL :server. Nehmen Sie die Werkzeuge von MySQL.', locale: 'de'),
            'not-mariadb' => __(':tool gehört zu MySQL, der Server ist MariaDB :server. Nehmen Sie die Werkzeuge von MariaDB.', locale: 'de'),
            default => __(':tool :version ist älter als der Server (MySQL :server). Nehmen Sie das Werkzeug in der Version des Servers oder neuer.', locale: 'de'),
        };
    }

    private function locate(string $tool): ?string
    {
        $configured = (string) (config('phpdbadmin.backup.tools.'.$tool) ?: $tool);

        if (str_contains($configured, '/')) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured, null, $this->directories());
    }

    /**
     * Zusätzliche Ordner: `~` steht für das Heimverzeichnis, `*` wird aufgelöst (neueste Version zuerst).
     *
     * @return list<string>
     */
    private function directories(): array
    {
        $home = (string) (getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : ''));
        $directories = [];

        foreach ((array) config('phpdbadmin.backup.directories', []) as $pattern) {
            $pattern = (string) $pattern;
            $expanded = str_starts_with($pattern, '~/') ? $home.substr($pattern, 1) : $pattern;
            $matches = str_contains($expanded, '*') ? (glob($expanded, GLOB_ONLYDIR) ?: []) : [$expanded];
            natsort($matches);

            foreach (array_reverse($matches) as $directory) {
                if (is_dir($directory)) {
                    $directories[] = $directory;
                }
            }
        }

        return $directories;
    }

    /**
     * @param  list<string>  $command
     */
    private function output(array $command): string
    {
        try {
            $process = new Process($command, timeout: 10);
            $process->run();

            return $process->getOutput().$process->getErrorOutput();
        } catch (Throwable) {
            return '';
        }
    }
}
