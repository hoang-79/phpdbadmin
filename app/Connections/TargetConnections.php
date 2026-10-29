<?php

namespace App\Connections;

use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Services\Catalog\Catalog;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;

/**
 * Öffnet gespeicherte Verbindungen für die laufende Anfrage (ADR-002): Passwort aus der App-Datenbank oder — bei
 * «nicht speichern» — verschlüsselt aus der Sitzung; schreibgeschützte Verbindungen nur lesend (V-04); Zeitlimit je
 * Anweisung. Alles, was geöffnet wurde, wird am Ende der Anfrage wieder freigegeben.
 */
final class TargetConnections
{
    private const string SESSION_KEY = 'target-passwords';

    /** @var array<string, Connection> */
    private array $open = [];

    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly Session $session,
        private readonly WriteUnlock $unlock,
    ) {}

    /**
     * @param  string|null  $database  andere Datenbank auf demselben Server — nur PostgreSQL mit «alle Datenbanken»
     *
     * @throws PasswordRequired
     * @throws ConnectionNotAllowed
     */
    public function open(DatabaseConnection $connection, ?string $database = null): Connection
    {
        $settings = $this->settings($connection, $database);

        $readOnly = $connection->read_only && ! $this->unlock->isActive($connection);

        // Eine Verbindung je tatsächlicher Datenbank: PostgreSQL ohne Namen ist dieselbe wie «postgres».
        $key = $connection->id.'#'.($settings->database ?? ($connection->driver === DatabaseDriver::Postgres ? Catalog::POSTGRES_MAINTENANCE_DATABASE : '')).($readOnly ? '#r' : '#w');

        if (isset($this->open[$key])) {
            return $this->open[$key];
        }

        return $this->open[$key] = $this->factory->make(
            $settings,
            readOnly: $readOnly,
            statementTimeoutMs: (int) config('phpdbadmin.connections.statement_timeout_ms', 15000),
        );
    }

    /**
     * Eine Verbindung ohne Schreibschutz für genau eine ändernde Aktion (W-02, W-04, W-05): nie für eine
     * schreibgeschützte Verbindung, getrennt von den Leseverbindungen der Anfrage und gleich danach wieder
     * freigegeben — auch wenn die Aktion scheitert.
     *
     * @template T
     *
     * @param  Closure(Connection): T  $action
     * @param  int|null  $statementTimeoutMs  Zeitlimit je Anweisung; ohne Angabe wie beim Lesen
     * @return T
     *
     * @throws ReadOnlyConnection
     * @throws PasswordRequired
     * @throws ConnectionNotAllowed
     */
    public function withWritable(DatabaseConnection $connection, Closure $action, ?string $database = null, ?int $statementTimeoutMs = null): mixed
    {
        if ($connection->read_only && ! $this->unlock->isActive($connection)) {
            throw new ReadOnlyConnection($connection);
        }

        $writable = $this->factory->make(
            $this->settings($connection, $database),
            statementTimeoutMs: $statementTimeoutMs ?? (int) config('phpdbadmin.connections.statement_timeout_ms', 15000),
        );

        try {
            return $action($writable);
        } finally {
            $this->factory->release($writable);
        }
    }

    /**
     * Eine eigene Verbindung neben denen der Anfrage — für das Abbrechen (`KILL QUERY`, `pg_cancel_backend`), das nicht
     * auf der blockierten Verbindung laufen kann. Schreibschutz wie bei open(); danach gleich wieder freigegeben.
     *
     * @template T
     *
     * @param  Closure(Connection): T  $action
     * @return T
     *
     * @throws PasswordRequired
     * @throws ConnectionNotAllowed
     */
    public function separate(DatabaseConnection $connection, Closure $action, ?string $database = null): mixed
    {
        $separate = $this->factory->make($this->settings($connection, $database), readOnly: $connection->read_only && ! $this->unlock->isActive($connection));

        try {
            return $action($separate);
        } finally {
            $this->factory->release($separate);
        }
    }

    /**
     * Zugangsdaten für die Werkzeuge des Servers (E-03: mysqldump, pg_dump …) — wie für open(), dazu die geprüfte
     * Adresse (V-05) bzw. der aufgelöste Pfad der SQLite-Datei. Das Passwort geht nie auf eine Kommandozeile.
     *
     * @return array{settings: ConnectionSettings, address: string}
     *
     * @throws PasswordRequired
     * @throws ConnectionNotAllowed
     */
    public function credentials(DatabaseConnection $connection, ?string $database = null): array
    {
        $settings = $this->settings($connection, $database);

        return ['settings' => $settings, 'address' => $this->factory->address($settings)];
    }

    /** Passwort für «in jeder Sitzung fragen» merken — verschlüsselt, nur in dieser Sitzung. */
    public function rememberPassword(DatabaseConnection $connection, string $password): void
    {
        $this->session->put(self::SESSION_KEY.'.'.$connection->id, Crypt::encryptString($password));
    }

    public function forgetPassword(DatabaseConnection $connection): void
    {
        $this->session->forget(self::SESSION_KEY.'.'.$connection->id);
    }

    public function releaseAll(): void
    {
        foreach ($this->open as $connection) {
            $this->factory->release($connection);
        }

        $this->open = [];
    }

    /**
     * @throws PasswordRequired
     */
    private function settings(DatabaseConnection $connection, ?string $database): ConnectionSettings
    {
        $password = $this->password($connection);

        if ($connection->driver->isServer() && ! $connection->store_password && $password === null) {
            throw new PasswordRequired($connection);
        }

        $settings = ConnectionSettings::fromConnection($connection, $password);

        return $database !== null && $connection->database === null ? $settings->withDatabase($database) : $settings;
    }

    private function password(DatabaseConnection $connection): ?string
    {
        if ($connection->store_password) {
            return $connection->password;
        }

        $stored = $this->session->get(self::SESSION_KEY.'.'.$connection->id);

        return is_string($stored) ? Crypt::decryptString($stored) : null;
    }
}
