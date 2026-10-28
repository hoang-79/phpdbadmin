<?php

namespace App\Connections;

use App\Models\DatabaseConnection;
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

    public function __construct(private readonly ConnectionFactory $factory, private readonly Session $session) {}

    /**
     * @param  string|null  $database  andere Datenbank auf demselben Server — nur PostgreSQL mit «alle Datenbanken»
     *
     * @throws PasswordRequired
     * @throws ConnectionNotAllowed
     */
    public function open(DatabaseConnection $connection, ?string $database = null): Connection
    {
        $key = $connection->id.'#'.($database ?? '');

        if (isset($this->open[$key])) {
            return $this->open[$key];
        }

        $password = $this->password($connection);

        if ($connection->driver->isServer() && ! $connection->store_password && $password === null) {
            throw new PasswordRequired($connection);
        }

        $settings = ConnectionSettings::fromConnection($connection, $password);

        if ($database !== null && $connection->database === null) {
            $settings = $settings->withDatabase($database);
        }

        return $this->open[$key] = $this->factory->make(
            $settings,
            readOnly: $connection->read_only,
            statementTimeoutMs: (int) config('phpdbadmin.connections.statement_timeout_ms', 15000),
        );
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

    private function password(DatabaseConnection $connection): ?string
    {
        if ($connection->store_password) {
            return $connection->password;
        }

        $stored = $this->session->get(self::SESSION_KEY.'.'.$connection->id);

        return is_string($stored) ? Crypt::decryptString($stored) : null;
    }
}
