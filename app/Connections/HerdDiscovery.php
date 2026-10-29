<?php

namespace App\Connections;

use App\Enums\DatabaseDriver;
use Dotenv\Dotenv;
use SimpleXMLElement;
use Throwable;

/**
 * Vorschläge aus Laravel Herd für «Verbindung anlegen» (V-07). Gelesen wird, was Herd selbst ablegt (macOS,
 * `~/Library/Application Support/Herd`):
 *
 * - `config/services.plist` — die Dienste mit Art, Version und Port; ob einer läuft, zeigt ein offener Port;
 * - `config/valet/config.json` — die Ordner der Sites (`paths`): geparkte Ordner und der Ordner der verknüpften Sites;
 * - die `.env` jeder Site — `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` (oder `DB_URL`).
 *
 * Nur, wenn die App lokal läuft (Vorgabe: `APP_ENV=local` und der Herd-Ordner existiert; PHPDBADMIN_HERD schaltet
 * ausdrücklich) und nur für Administratoren. Passwörter aus fremden `.env` erreichen nie den Browser: Der Vorschlag
 * sagt nur, ob es eines gibt; beim Speichern oder Testen liest der Server es aus derselben Quelle — und nur für
 * denselben Host, Port und Benutzer.
 */
final class HerdDiscovery
{
    /** Höchstzahl der Sites, deren `.env` gelesen wird. */
    private const int MAX_SITES = 300;

    /** @var array<string, string> Passwörter je Vorschlag, nur im Server */
    private array $passwords = [];

    public function __construct(private readonly HostPolicy $policy) {}

    public function enabled(): bool
    {
        $configured = config('phpdbadmin.herd.enabled');

        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN) && is_dir($this->path());
        }

        return app()->isLocal() && is_dir($this->path());
    }

    public function path(): string
    {
        $configured = config('phpdbadmin.herd.path');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '')));

        return $home.'/Library/Application Support/Herd';
    }

    /**
     * Dienste zuerst, dann die Sites nach Name.
     *
     * @return list<HerdSuggestion>
     */
    public function suggestions(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $this->passwords = [];
        $sites = $this->sites();
        usort($sites, fn (HerdSuggestion $a, HerdSuggestion $b): int => strnatcasecmp($a->name, $b->name));

        // Was diese Installation nicht erlaubt (Host, SQLite-Ordner), steht da — aber als nicht übernehmbar.
        return array_map(fn (HerdSuggestion $suggestion): HerdSuggestion => $suggestion->withAllowed($this->allowed($suggestion)), [...$this->services(), ...$sites]);
    }

    /**
     * Passwort aus der Quelle eines Vorschlags — nur, wenn Host, Port und Benutzer zu ihr passen; sonst null.
     */
    public function passwordFor(string $id, ?string $host, ?int $port, ?string $username): ?string
    {
        $suggestion = array_find($this->suggestions(), fn (HerdSuggestion $candidate): bool => $candidate->id === $id);

        if ($suggestion === null || $suggestion->origin !== HerdSuggestion::SITE || ! isset($this->passwords[$id])) {
            return null;
        }

        $samePort = ($port ?? $suggestion->driver->defaultPort()) === ($suggestion->port ?? $suggestion->driver->defaultPort());

        return strcasecmp((string) $host, (string) $suggestion->host) === 0 && $samePort && (string) $username === (string) $suggestion->username
            ? $this->passwords[$id]
            : null;
    }

    /**
     * @return list<HerdSuggestion>
     */
    private function services(): array
    {
        $file = $this->path().'/config/services.plist';

        if (! is_file($file)) {
            return [];
        }

        $suggestions = [];

        foreach ($this->plist((string) file_get_contents($file)) as $service) {
            $driver = match (strtolower((string) ($service['type'] ?? ''))) {
                'mysql' => DatabaseDriver::MySql,
                'mariadb' => DatabaseDriver::MariaDb,
                'postgresql', 'postgres', 'pgsql' => DatabaseDriver::Postgres,
                default => null,
            };
            $port = (int) ($service['port'] ?? 0);

            if ($driver === null || $port < 1 || $port > 65535) {
                continue;
            }

            $suggestions[] = new HerdSuggestion(
                id: 'service:'.preg_replace('/[^A-Za-z0-9-]/', '', (string) ($service['id'] ?? $driver->value.$port)),
                origin: HerdSuggestion::SERVICE,
                name: (string) ($service['name'] ?? $driver->label()),
                driver: $driver,
                host: '127.0.0.1',
                port: $port,
                // Herd legt die Dienste mit dem Konto root ohne Passwort an.
                username: 'root',
                database: $driver === DatabaseDriver::Postgres ? 'postgres' : null,
                hasPassword: false,
                running: $this->listening($port),
                version: isset($service['version']) ? (string) $service['version'] : null,
            );
        }

        return $suggestions;
    }

    /**
     * @return list<HerdSuggestion>
     */
    private function sites(): array
    {
        $suggestions = [];
        $seen = [];

        foreach ($this->siteFolders() as $name => $folder) {
            if (count($seen) >= self::MAX_SITES) {
                break;
            }

            $real = realpath($folder);

            if ($real === false || isset($seen[$real]) || ! is_file($real.'/.env') || filesize($real.'/.env') > 65536) {
                continue;
            }

            $seen[$real] = true;
            $suggestion = $this->site($name, $real);

            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Ordner der Sites: jeder Unterordner eines geparkten Ordners, im Ordner der verknüpften Sites jede Verknüpfung.
     *
     * @return array<string, string> Name → Ordner
     */
    private function siteFolders(): array
    {
        $config = $this->path().'/config/valet/config.json';
        $decoded = is_file($config) ? json_decode((string) file_get_contents($config), true) : null;
        $paths = is_array($decoded) && is_array($decoded['paths'] ?? null) ? $decoded['paths'] : [];
        $folders = [];

        foreach ($paths as $path) {
            if (! is_string($path) || ! is_dir($path)) {
                continue;
            }

            foreach (scandir($path) ?: [] as $entry) {
                if ($entry[0] === '.' || ! is_dir($path.'/'.$entry)) {
                    continue;
                }

                $folders[$entry] ??= rtrim($path, '/').'/'.$entry;
            }
        }

        return $folders;
    }

    private function site(string $name, string $folder): ?HerdSuggestion
    {
        try {
            $env = Dotenv::parse((string) file_get_contents($folder.'/.env'));
        } catch (Throwable) {
            return null;
        }

        $value = fn (string $key): ?string => isset($env[$key]) && $env[$key] !== '' ? (string) $env[$key] : null;
        $url = $value('DB_URL') !== null ? parse_url((string) $value('DB_URL')) : false;
        $url = is_array($url) ? $url : [];

        $driver = DatabaseDriver::tryFrom(strtolower((string) ($value('DB_CONNECTION') ?? ($url['scheme'] ?? ''))));

        if ($driver === null) {
            return null;
        }

        $id = 'site:'.substr(hash('xxh128', $folder), 0, 16);

        if ($driver === DatabaseDriver::Sqlite) {
            $database = $value('DB_DATABASE') ?? 'database/database.sqlite';
            $path = str_starts_with($database, '/') ? $database : $folder.'/'.$database;

            return is_file($path)
                ? new HerdSuggestion($id, HerdSuggestion::SITE, $name, $driver, null, null, null, null, false, sqlitePath: (string) realpath($path), folder: $folder)
                : null;
        }

        $password = $value('DB_PASSWORD') ?? (isset($url['pass']) ? rawurldecode((string) $url['pass']) : null);

        if ($password !== null) {
            $this->passwords[$id] = $password;
        }

        return new HerdSuggestion(
            id: $id,
            origin: HerdSuggestion::SITE,
            name: $name,
            driver: $driver,
            host: $value('DB_HOST') ?? (isset($url['host']) ? (string) $url['host'] : '127.0.0.1'),
            port: (int) ($value('DB_PORT') ?? ($url['port'] ?? $driver->defaultPort())),
            username: $value('DB_USERNAME') ?? (isset($url['user']) ? rawurldecode((string) $url['user']) : null),
            database: $value('DB_DATABASE') ?? (isset($url['path']) ? ltrim((string) $url['path'], '/') : null),
            hasPassword: $password !== null,
            folder: $folder,
        );
    }

    /**
     * Die Liste der Dienste aus Herds XML-Property-List: ein Array von Dictionaries mit Text und Zahlen.
     *
     * @return list<array<string, string>>
     */
    private function plist(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($root === false || ! isset($root->array)) {
            return [];
        }

        $services = [];

        foreach ($root->array->dict as $dict) {
            $service = [];
            $key = null;

            foreach ($dict->children() as $node) {
                if ($node->getName() === 'key') {
                    $key = (string) $node;
                } elseif ($key !== null) {
                    $service[$key] = in_array($node->getName(), ['true', 'false'], true) ? $node->getName() : (string) $node;
                    $key = null;
                }
            }

            $services[] = $service;
        }

        return $services;
    }

    private function allowed(HerdSuggestion $suggestion): bool
    {
        try {
            $suggestion->driver === DatabaseDriver::Sqlite
                ? $this->policy->resolveSqlitePath((string) $suggestion->sqlitePath)
                : $this->policy->resolveHost((string) $suggestion->host);

            return true;
        } catch (ConnectionNotAllowed) {
            return false;
        }
    }

    private function listening(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
