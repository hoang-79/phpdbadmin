<?php

namespace App\Connections;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Erlaubte Hosts und SQLite-Ordner (V-05, T-05, T-08). Geprüft wird beim Speichern und bei jedem Verbinden.
 *
 * Hosts: Ein Name gilt, wenn er selbst auf der Liste steht (auch «*.example.ch») oder **jede** seiner Adressen in
 * einem erlaubten Netz liegt. Der Name wird genau einmal aufgelöst; verbunden wird zur geprüften Adresse — ein
 * zweites Auflösen könnte eine andere Adresse liefern (DNS-Rebinding).
 *
 * SQLite: Der Pfad wird mit realpath() aufgelöst (gegen «..» und Verknüpfungen nach draussen) und muss in einem
 * erlaubten Ordner liegen. Die App-Datenbank von phpdbadmin selbst ist nie erreichbar.
 */
final class HostPolicy
{
    /**
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $sqliteFolders
     */
    public function __construct(
        private readonly array $allowedHosts,
        private readonly array $sqliteFolders,
        private readonly ?string $appDatabase = null,
    ) {}

    public static function fromConfig(): self
    {
        $appDatabase = config('database.default') === 'sqlite'
            ? (string) config('database.connections.sqlite.database')
            : null;

        return new self(
            array_values(array_map('strval', (array) config('phpdbadmin.connections.allowed_hosts', []))),
            array_values(array_map('strval', (array) config('phpdbadmin.connections.sqlite_paths', []))),
            $appDatabase,
        );
    }

    /**
     * @return string die geprüfte Adresse, zu der verbunden wird
     *
     * @throws ConnectionNotAllowed
     */
    public function resolveHost(string $host): string
    {
        $name = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($name === '') {
            throw new ConnectionNotAllowed(ConnectionProblem::of(ConnectionProblemKind::HostUnknown));
        }

        $addresses = filter_var($name, FILTER_VALIDATE_IP) !== false ? [$name] : $this->lookup($name);

        if ($addresses === []) {
            throw new ConnectionNotAllowed(ConnectionProblem::of(ConnectionProblemKind::HostUnknown, $name));
        }

        $nameAllowed = $this->nameIsListed($name);
        $networks = array_values(array_filter($this->allowedHosts, fn (string $entry): bool => $this->isNetwork($entry)));
        $addressesAllowed = $networks !== [] && array_all($addresses, fn (string $address): bool => IpUtils::checkIp($address, $networks));

        if (! $nameAllowed && ! $addressesAllowed) {
            throw new ConnectionNotAllowed(ConnectionProblem::of(ConnectionProblemKind::HostNotAllowed, $name));
        }

        return array_find($addresses, fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
            ?? $addresses[0];
    }

    /**
     * @return string der aufgelöste, geprüfte Pfad
     *
     * @throws ConnectionNotAllowed
     */
    public function resolveSqlitePath(string $path): string
    {
        $expanded = $this->expandHome(trim($path));
        $real = realpath($expanded);

        if ($real === false || ! is_file($real)) {
            throw new ConnectionNotAllowed(ConnectionProblem::of(ConnectionProblemKind::FileMissing, $path));
        }

        $appDatabase = $this->appDatabase !== null ? realpath($this->appDatabase) : false;

        if ($appDatabase !== false && $real === $appDatabase) {
            throw new ConnectionNotAllowed(ConnectionProblem::of(
                ConnectionProblemKind::FileNotAllowed,
                ConnectionProblem::APP_DATABASE,
            ));
        }

        foreach ($this->sqliteFolders as $folder) {
            $realFolder = realpath($this->expandHome($folder));

            if ($realFolder !== false && str_starts_with($real, rtrim($realFolder, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        throw new ConnectionNotAllowed(ConnectionProblem::of(ConnectionProblemKind::FileNotAllowed, $path));
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        return $this->allowedHosts;
    }

    /**
     * @return list<string>
     */
    public function sqliteFolders(): array
    {
        return $this->sqliteFolders;
    }

    private function nameIsListed(string $name): bool
    {
        foreach ($this->allowedHosts as $entry) {
            $entry = strtolower($entry);

            if ($entry === $name) {
                return true;
            }

            if (str_starts_with($entry, '*.') && str_ends_with($name, substr($entry, 1))) {
                return true;
            }
        }

        return false;
    }

    private function isNetwork(string $entry): bool
    {
        $address = explode('/', $entry, 2)[0];

        return filter_var($address, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return list<string>
     */
    private function lookup(string $name): array
    {
        $addresses = gethostbynamel($name) ?: [];

        $records = @dns_get_record($name, DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private function expandHome(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            // PHP-FPM setzt HOME nicht immer; dann aus dem Konto des Prozesses.
            $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '')));

            return $home.substr($path, 1);
        }

        return $path;
    }
}
