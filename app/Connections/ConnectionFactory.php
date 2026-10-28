<?php

namespace App\Connections;

use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Pdo\Sqlite;

/**
 * Baut Zielverbindungen je Anfrage (ADR-002): Host bzw. Datei zuerst gegen die HostPolicy, dann
 * die Konfiguration des Dialekts. Schreibgeschützt (V-04) in Schichten: Sitzung bzw. Öffnen nur lesend, dazu der
 * Wächter je Anweisung (Dialect::runReadOnly). Nach der Arbeit freigeben (release).
 */
final class ConnectionFactory
{
    /** SQLITE_ATTACH im Authorizer von SQLite. */
    private const int SQLITE_ATTACH = 24;

    /** Name der Faltfunktion für Vergleiche ohne Gross- und Kleinschreibung in SQLite. */
    public const string SQLITE_FOLD = 'phpdbadmin_fold';

    public function __construct(private readonly HostPolicy $policy) {}

    /**
     * @param  int|null  $statementTimeoutMs  Zeitlimit je Anweisung (wo der Treiber eines kennt)
     *
     * @throws ConnectionNotAllowed
     */
    public function make(ConnectionSettings $settings, ?int $connectTimeout = null, bool $readOnly = false, ?int $statementTimeoutMs = null): Connection
    {
        $dialect = $settings->driver->dialect();
        $address = $this->address($settings);
        $config = $dialect->connectionConfig(
            $settings,
            $address,
            $connectTimeout ?? (int) config('phpdbadmin.connections.connect_timeout', 5),
            $readOnly,
        );
        // Name nur für Protokolle und Fehlermeldungen — die Verbindung steht in keinem globalen Register (siehe unten).
        $config['name'] = 'target_'.hash('xxh128', serialize([$config, $readOnly, $statementTimeoutMs]));

        $connection = $this->build($config);
        // Sofort verbinden: Scheitert erst die erste Abfrage, hält Laravel «Connection refused» für eine verlorene
        // Verbindung und versucht neu — so kommt ein nicht erreichbarer Server als gewöhnlicher PDO-Fehler an.
        $connection->getPdo();

        $prepare = function (Connection $connection) use ($settings, $dialect, $readOnly, $statementTimeoutMs): void {
            if ($settings->driver === DatabaseDriver::Sqlite) {
                $this->guardAttach($connection);
                $this->registerFold($connection);
            }

            $dialect->prepareSession($connection, $readOnly);

            if ($statementTimeoutMs !== null) {
                $dialect->setStatementTimeout($connection, $statementTimeoutMs);
            }
        };

        $prepare($connection);

        // Laravel hält «SQLSTATE[25006] Read only sql transaction» für eine verlorene Verbindung (Aurora-Umschaltung),
        // verbindet neu und wiederholt die Anweisung — auf einer Sitzung ohne Schreibschutz, Authorizer und Zeitlimit.
        // Deshalb erneuert der Neuverbinder genau dieses Objekt und richtet die Sitzung wieder ein; die Wiederholung
        // scheitert dann wie sie soll. Auch hier sofort verbinden, sonst riefe sich der Neuverbinder beim Einrichten
        // der Sitzung auf einem weiterhin nicht erreichbaren Server selbst auf.
        $connection->setReconnector(function (Connection $connection) use ($config, $prepare): void {
            $fresh = $this->build($config);
            $connection->setPdo($fresh->getPdo())->setReadPdo($fresh->getRawReadPdo());
            $prepare($connection);
        });

        return $connection;
    }

    /**
     * Verbindung bauen, ohne sie im DatabaseManager zu registrieren: zwei Zielverbindungen mit gleicher
     * Konfiguration würden sich dort unter einem Namen gegenseitig trennen (DB::build ersetzt die vorhandene), und
     * ein Neuaufbau über den Manager träfe das falsche Objekt. Ereignisse (Protokolle der Abfragen) und
     * Transaktionen wie bei Laravels eigenen Verbindungen.
     *
     * @param  array<string, mixed>  $config
     */
    private function build(array $config): Connection
    {
        /** @var Connection $connection */
        $connection = app('db.factory')->make($config, (string) $config['name']);

        if (app()->bound('events')) {
            $connection->setEventDispatcher(app('events'));
        }

        if (app()->bound('db.transactions')) {
            $connection->setTransactionManager(app('db.transactions'));
        }

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
    }

    /**
     * SQLite faltet Gross- und Kleinschreibung nur für ASCII; «enthält» und die Suche im Raster (D-02) nutzen deshalb
     * diese Funktion, damit «MÜLLER» auch «Müller» findet — wie bei den Servern.
     */
    private function registerFold(Connection $connection): void
    {
        $pdo = $connection->getPdo();

        if ($pdo instanceof Sqlite) {
            $pdo->createFunction(self::SQLITE_FOLD, fn (mixed $value): ?string => $value === null ? null : mb_strtolower((string) $value), 1, Sqlite::DETERMINISTIC);
        }
    }

    /**
     * ATTACH nur für Dateien in erlaubten Ordnern (T-08, F-7). Ohne Dateinamen beim Vorbereiten («ATTACH ?») wird
     * abgewiesen — der Authorizer kann gebundene Werte nicht sehen. Erlaubt sind dagegen eine leere temporäre und
     * eine Datenbank im Speicher: Sie öffnen keine Datei, und VACUUM hängt intern genau so eine an («ATTACH ''»).
     */
    private function guardAttach(Connection $connection): void
    {
        $pdo = $connection->getPdo();

        if (! $pdo instanceof Sqlite) {
            return;
        }

        $pdo->setAuthorizer(function (int $action, ?string $file): int {
            if ($action !== self::SQLITE_ATTACH || $file === '' || $file === ':memory:') {
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
