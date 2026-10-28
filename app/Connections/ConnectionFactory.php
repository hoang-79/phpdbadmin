<?php

namespace App\Connections;

use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Baut Zielverbindungen je Anfrage über DB::build() (ADR-002): Host bzw. Datei zuerst gegen die HostPolicy, dann
 * die Konfiguration des Dialekts. Nach der Arbeit wieder freigeben (release), damit keine Verbindung offen bleibt.
 */
final class ConnectionFactory
{
    public function __construct(private readonly HostPolicy $policy) {}

    /**
     * @throws ConnectionNotAllowed
     */
    public function make(ConnectionSettings $settings, ?int $connectTimeout = null): Connection
    {
        $address = $this->address($settings);
        $config = $settings->driver->dialect()->connectionConfig(
            $settings,
            $address,
            $connectTimeout ?? (int) config('phpdbadmin.connections.connect_timeout', 5),
        );

        /** @var Connection $connection */
        $connection = DB::build($config);

        return $connection;
    }

    /**
     * Die geprüfte Adresse (Server) bzw. der aufgelöste Pfad (SQLite).
     *
     * @throws ConnectionNotAllowed
     */
    public function address(ConnectionSettings $settings): string
    {
        return $settings->driver === DatabaseDriver::Sqlite
            ? $this->policy->resolveSqlitePath((string) $settings->sqlitePath)
            : $this->policy->resolveHost((string) $settings->host);
    }

    public function release(Connection $connection): void
    {
        $connection->disconnect();
        DB::purge((string) $connection->getName());
    }
}
