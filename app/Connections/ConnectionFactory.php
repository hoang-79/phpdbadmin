<?php

namespace App\Connections;

use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Pdo\Sqlite;

/**
 * Baut Zielverbindungen je Anfrage über DB::build() (ADR-002): Host bzw. Datei zuerst gegen die HostPolicy, dann
 * die Konfiguration des Dialekts. Schreibgeschützt (V-04) in Schichten: Sitzung bzw. Öffnen nur lesend, dazu der
 * Wächter je Anweisung (Dialect::runReadOnly). Nach der Arbeit freigeben (release).
 */
final class ConnectionFactory
{
    /** SQLITE_ATTACH im Authorizer von SQLite. */
    private const int SQLITE_ATTACH = 24;

    public function __construct(private readonly HostPolicy $policy) {}

    /**
     * @throws ConnectionNotAllowed
     */
    public function make(ConnectionSettings $settings, ?int $connectTimeout = null, bool $readOnly = false): Connection
    {
        $dialect = $settings->driver->dialect();
        $address = $this->address($settings);
        $config = $dialect->connectionConfig(
            $settings,
            $address,
            $connectTimeout ?? (int) config('phpdbadmin.connections.connect_timeout', 5),
            $readOnly,
        );
        // Eigener Name je Zweck: eine schreibgeschützte und eine beschreibbare Verbindung teilen sich nie den Zustand.
        $config['name'] = 'target_'.hash('xxh128', serialize([$config, $readOnly]));

        /** @var Connection $connection */
        $connection = DB::build($config);

        if ($settings->driver === DatabaseDriver::Sqlite) {
            $this->guardAttach($connection);
        }

        $dialect->prepareSession($connection, $readOnly);

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

    /**
     * ATTACH nur für Dateien in erlaubten Ordnern (T-08, F-7). Ohne Dateinamen beim Vorbereiten («ATTACH ?») wird
     * abgewiesen — der Authorizer kann gebundene Werte nicht sehen.
     */
    private function guardAttach(Connection $connection): void
    {
        $pdo = $connection->getPdo();

        if (! $pdo instanceof Sqlite) {
            return;
        }

        $pdo->setAuthorizer(function (int $action, ?string $file): int {
            if ($action !== self::SQLITE_ATTACH) {
                return Sqlite::OK;
            }

            try {
                $this->policy->resolveSqlitePath((string) $file);

                return Sqlite::OK;
            } catch (ConnectionNotAllowed) {
                return Sqlite::DENY;
            }
        });
    }
}
