<?php

namespace App\Connections;

use App\Enums\DatabaseDriver;

/**
 * Ein Vorschlag aus Laravel Herd für «Verbindung anlegen» (V-07): ein laufender Dienst oder die Datenbank einer
 * Herd-Site aus deren `.env`. Das Passwort steht nie hier — nur, ob es eines gibt; beim Übernehmen liest der Server
 * es aus der Quelle (HerdDiscovery::passwordFor).
 */
final readonly class HerdSuggestion
{
    public const string SERVICE = 'service';

    public const string SITE = 'site';

    /**
     * @param  self::SERVICE|self::SITE  $origin
     */
    public function __construct(
        public string $id,
        public string $origin,
        public string $name,
        public DatabaseDriver $driver,
        public ?string $host,
        public ?int $port,
        public ?string $username,
        public ?string $database,
        public bool $hasPassword,
        public ?string $sqlitePath = null,
        public ?bool $running = null,
        public ?string $version = null,
        public ?string $folder = null,
        public bool $allowed = true,
    ) {}

    public function withAllowed(bool $allowed): self
    {
        return new self($this->id, $this->origin, $this->name, $this->driver, $this->host, $this->port, $this->username, $this->database, $this->hasPassword, $this->sqlitePath, $this->running, $this->version, $this->folder, $allowed);
    }

    /**
     * Für den Browser — ohne Passwort.
     *
     * @return array{id: string, origin: string, name: string, driver: string, driverLabel: string, host: string|null, port: int|null, username: string|null, database: string|null, hasPassword: bool, sqlitePath: string|null, running: bool|null, version: string|null, folder: string|null, allowed: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'origin' => $this->origin,
            'name' => $this->name,
            'driver' => $this->driver->value,
            'driverLabel' => $this->driver->label(),
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'database' => $this->database,
            'hasPassword' => $this->hasPassword,
            'sqlitePath' => $this->sqlitePath,
            'running' => $this->running,
            'version' => $this->version,
            'folder' => $this->folder,
            'allowed' => $this->allowed,
        ];
    }
}
