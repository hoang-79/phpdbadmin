<?php

namespace App\Connections;

use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use App\Models\DatabaseConnection;

/**
 * Was es braucht, um eine Zielverbindung aufzubauen — aus einer gespeicherten Verbindung oder aus dem Formular
 * («Verbindung testen» vor dem Speichern).
 */
final readonly class ConnectionSettings
{
    public function __construct(
        public DatabaseDriver $driver,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $database = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $sqlitePath = null,
        public SslMode $sslMode = SslMode::Disable,
    ) {}

    public static function fromConnection(DatabaseConnection $connection, ?string $password = null): self
    {
        return new self(
            driver: $connection->driver,
            host: $connection->host,
            port: $connection->port,
            database: $connection->database,
            username: $connection->username,
            password: $password ?? $connection->password,
            sqlitePath: $connection->sqlite_path,
            sslMode: $connection->sslMode(),
        );
    }

    /**
     * @param  array<string, mixed>  $input  geprüfte Eingaben aus dem Formular
     */
    public static function fromInput(array $input): self
    {
        $driver = DatabaseDriver::from((string) $input['driver']);
        $text = fn (string $key): ?string => isset($input[$key]) && $input[$key] !== '' ? (string) $input[$key] : null;

        return new self(
            driver: $driver,
            host: $driver->isServer() ? $text('host') : null,
            port: $driver->isServer() && isset($input['port']) && $input['port'] !== '' ? (int) $input['port'] : null,
            database: $driver->isServer() && ! filter_var($input['all_databases'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $text('database') : null,
            username: $driver->isServer() ? $text('username') : null,
            password: $driver->isServer() ? $text('password') : null,
            sqlitePath: $driver->isServer() ? null : $text('sqlite_path'),
            sslMode: SslMode::tryFrom((string) ($input['sslmode'] ?? '')) ?? SslMode::Disable,
        );
    }

    public function withPassword(?string $password): self
    {
        return new self($this->driver, $this->host, $this->port, $this->database, $this->username, $password, $this->sqlitePath, $this->sslMode);
    }

    /** Andere Datenbank auf demselben Server (PostgreSQL mit «alle Datenbanken»). */
    public function withDatabase(?string $database): self
    {
        return new self($this->driver, $this->host, $this->port, $database, $this->username, $this->password, $this->sqlitePath, $this->sslMode);
    }

    public function port(): ?int
    {
        return $this->port ?? $this->driver->defaultPort();
    }
}
