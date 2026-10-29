<?php

namespace App\Connections;

use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Pdo\Sqlite;
use Throwable;

/**
 * Baut Zielverbindungen je Anfrage (ADR-002): Host bzw. Datei zuerst gegen die HostPolicy, dann
 * die Konfiguration des Dialekts. Schreibgeschützt (V-04) in Schichten: Sitzung bzw. Öffnen nur lesend, dazu der
 * Wächter je Anweisung (Dialect::runReadOnly). Nach der Arbeit freigeben (release).
 *
 * Mit SSH-Tunnel (V-08) verbindet der Treiber zum lokalen Ende des Tunnels; geprüft werden vorher der SSH-Server und
 * das Ziel aus Sicht des Tunnels nach denselben Regeln (HostPolicy::resolveTunnelTarget). Der Tunnel lebt so lange
 * wie die Verbindung.
 */
final class ConnectionFactory
{
    /** SQLITE_ATTACH im Authorizer von SQLite. */
    private const int SQLITE_ATTACH = 24;

    /** Name der Faltfunktion für Vergleiche ohne Gross- und Kleinschreibung in SQLite. */
    public const string SQLITE_FOLD = 'phpdbadmin_fold';

    public function __construct(
        private readonly HostPolicy $policy,
        private readonly SshTunnels $tunnels,
    ) {}

    /**
     * @param  int|null  $statementTimeoutMs  Zeitlimit je Anweisung (wo der Treiber eines kennt)
     *
     * @throws ConnectionNotAllowed
     * @throws TunnelFailed
     */
    public function make(ConnectionSettings $settings, ?int $connectTimeout = null, bool $readOnly = false, ?int $statementTimeoutMs = null): Connection
    {
        $dialect = $settings->driver->dialect();
        // Zuerst prüfen (Hosts, Datenbankname, App-Datenbank), dann erst einen Tunnel öffnen.
        $address = $this->address($settings);
        $tunnel = $this->tunnel($settings);
        $config = $dialect->connectionConfig(
            $tunnel !== null ? $settings->throughTunnel($tunnel->port) : $settings,
            $tunnel !== null ? '127.0.0.1' : $address,
            $connectTimeout ?? (int) config('phpdbadmin.connections.connect_timeout', 5),
            $readOnly,
        );
        // Laravel setzt gebundene Werte sonst in die Meldung jeder QueryException ein (B-04, T-11); die Werte in den
        // Meldungen der Server selbst ersetzt App\Services\Audit\Masking.
        $config['mask_bindings_in_exception_messages'] = true;
        // Name nur für Protokolle und Fehlermeldungen — die Verbindung steht in keinem globalen Register (siehe unten).
        $config['name'] = 'target_'.hash('xxh128', serialize([$config, $readOnly, $statementTimeoutMs]));

        try {
            $connection = $this->build($config);
            // Sofort verbinden: Scheitert erst die erste Abfrage, hält Laravel «Connection refused» für eine verlorene
            // Verbindung und versucht neu — so kommt ein nicht erreichbarer Server als gewöhnlicher PDO-Fehler an.
            $connection->getPdo();
        } catch (Throwable $exception) {
            if ($tunnel !== null) {
                // Der Tunnel steht, aber der SSH-Server erreicht die Datenbank nicht — das sagt nur ssh.
                $problem = SshTunnels::problem($tunnel->errorOutput());
                $this->tunnels->release($tunnel);

                if ($problem->kind === ConnectionProblemKind::SshForwardFailed) {
                    throw new TunnelFailed($problem);
                }
            }

            throw $exception;
        }

        if ($tunnel !== null) {
            $this->tunnels->attach($connection, $tunnel);
        }

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
     * Die geprüfte Adresse (Server) bzw. der aufgelöste Pfad (SQLite). Mit SSH-Tunnel: das Ziel aus Sicht des
     * SSH-Servers — der SSH-Server selbst muss dafür erlaubt sein.
     *
     * @throws ConnectionNotAllowed
     */
    public function address(ConnectionSettings $settings): string
    {
        if ($settings->driver === DatabaseDriver::Sqlite) {
            return $this->policy->resolveSqlitePath((string) $settings->sqlitePath);
        }

        // Über SSH (V-08): der SSH-Server wie jeder Host, das Ziel aus Sicht des Tunnels nach denselben Regeln.
        if ($settings->ssh !== null) {
            $this->policy->resolveHost($settings->ssh->host);
            $address = $this->policy->resolveTunnelTarget((string) $settings->host);
        } else {
            $address = $this->policy->resolveHost((string) $settings->host);
        }

        // Datenbankname gegen DSN-Injektion und die App-Datenbank (T-05, T-17) — auch hinter einem Tunnel.
        $this->policy->guardDatabase($settings->driver, $address, $settings->port, $settings->database);

        return $address;
    }

    /**
     * SSH-Tunnel für diese Angaben öffnen (oder einen offenen mitbenutzen); null ohne SSH. Wer ihn selbst öffnet, gibt
     * ihn mit releaseTunnel() wieder frei.
     *
     * @throws ConnectionNotAllowed
     * @throws TunnelFailed
     */
    public function tunnel(ConnectionSettings $settings): ?SshTunnel
    {
        if ($settings->ssh === null || ! $settings->driver->isServer()) {
            return null;
        }

        return $this->tunnels->open(
            $settings->ssh,
            $this->policy->resolveHost($settings->ssh->host),
            $this->policy->resolveTunnelTarget((string) $settings->host),
            (int) $settings->port(),
            $this->policy,
        );
    }

    public function releaseTunnel(SshTunnel $tunnel): void
    {
        $this->tunnels->release($tunnel);
    }

    public function release(Connection $connection): void
    {
        $connection->disconnect();
        $this->tunnels->releaseFor($connection);
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
