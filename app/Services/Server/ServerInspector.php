<?php

namespace App\Services\Server;

use App\Connections\TargetConnections;
use App\Dialects\Dialect;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Liest für die Server-Verwaltung (W-01 bis W-04), was jede Person mit Zugang zur Verbindung sehen darf — und welche
 * Datenbanken, Schemas und Tabellen als Ziel einer Änderung gelten: dieselben wie im Navigator.
 *
 * @phpstan-import-type ServerOverview from Dialect
 * @phpstan-import-type ServerProcess from Dialect
 * @phpstan-import-type ServerSetting from Dialect
 * @phpstan-import-type ServerAccount from Dialect
 */
final class ServerInspector
{
    public function __construct(private readonly TargetConnections $targets) {}

    /**
     * @return ServerOverview
     */
    public function overview(DatabaseConnection $connection, ?string $database = null): array
    {
        return $connection->driver->dialect()->serverOverview($this->targets->open($connection, $database));
    }

    /**
     * @return list<ServerProcess>|null
     */
    public function processes(DatabaseConnection $connection, ?string $database = null): ?array
    {
        return $connection->driver->dialect()->processes($this->targets->open($connection, $database));
    }

    /**
     * @return array{variables: list<ServerSetting>, status: list<ServerSetting>}
     */
    public function settings(DatabaseConnection $connection, ?string $database = null): array
    {
        $target = $this->targets->open($connection, $database);
        $dialect = $connection->driver->dialect();

        return ['variables' => $dialect->variables($target), 'status' => $dialect->statusValues($target)];
    }

    /**
     * Konten mit Rechten; darf das Konto von phpdbadmin sie nicht lesen, steht das als Hinweis da statt eines Fehlers.
     *
     * @return array{accounts: list<ServerAccount>|null, problem: string|null}
     */
    public function accounts(DatabaseConnection $connection, ?string $database = null): array
    {
        try {
            return ['accounts' => $connection->driver->dialect()->accounts($this->targets->open($connection, $database)), 'problem' => null];
        } catch (QueryException $exception) {
            return ['accounts' => [], 'problem' => (string) ($exception->getPrevious()?->getMessage() ?? $exception->errorInfo[2] ?? '')];
        }
    }

    /**
     * Auswahl für das Ziel eines Rechts: Datenbanken und (PostgreSQL) die Schemas der geöffneten Datenbank.
     *
     * @return array{databases: list<string>, schemas: list<string>|null}
     */
    public function privilegeTargets(DatabaseConnection $connection, ?string $database = null): array
    {
        $target = $this->targets->open($connection, $database);

        return [
            'databases' => $this->databasesOn($this->targets->open($connection), $connection),
            'schemas' => $connection->driver === DatabaseDriver::Postgres ? $this->schemasOn($target, $connection) : null,
        ];
    }

    /**
     * Gewählte Datenbank für Änderungen bei PostgreSQL mit «alle Datenbanken» — nur aus der Liste des Servers (sie
     * steht danach in der Adresse der Verbindung); sonst null (die eingetragene).
     *
     * @throws ValidationException
     */
    public function database(DatabaseConnection $connection, ?string $requested): ?string
    {
        if ($connection->driver !== DatabaseDriver::Postgres || $connection->database !== null || $requested === null) {
            return null;
        }

        if (! in_array($requested, $this->databasesOn($this->targets->open($connection), $connection), true)) {
            throw ValidationException::withMessages(['database' => __('Diese Datenbank gibt es auf dem Server nicht.')]);
        }

        return $requested;
    }

    /**
     * Datenbanken als Ziel von Rechten: MySQL und MariaDB wie im Navigator, PostgreSQL die des Servers bzw. die
     * eingetragene.
     *
     * @return list<string>
     */
    public function databasesOn(Connection $target, DatabaseConnection $connection): array
    {
        if ($connection->driver !== DatabaseDriver::Postgres) {
            return $this->schemasOn($target, $connection);
        }

        return $connection->database !== null ? [$connection->database] : (array) $connection->driver->dialect()->databases($target);
    }

    /**
     * Schemas wie im Navigator: MySQL und MariaDB mit eingetragener Datenbank nur diese.
     *
     * @return list<string>
     */
    public function schemasOn(Connection $target, DatabaseConnection $connection): array
    {
        $schemas = $connection->driver->dialect()->schemas($target);

        if (in_array($connection->driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) && $connection->database !== null) {
            return array_values(array_intersect($schemas, [$connection->database]));
        }

        return $schemas;
    }

    /**
     * Tabellen eines Schemas, auf Wunsch mit Sichten (Rechte gelten auch für Sichten, Wartung nur für Tabellen).
     *
     * @return list<string>
     */
    public function tablesOn(Connection $target, DatabaseConnection $connection, string $schema, bool $withViews): array
    {
        return array_values(array_map(
            fn (array $table): string => $table['name'],
            array_filter($connection->driver->dialect()->tables($target, $schema), fn (array $table): bool => $withViews || $table['type'] === 'table'),
        ));
    }

    /**
     * Tabellen eines Schemas für die Auswahl in der Oberfläche — nur aus einem Schema, das es gibt.
     *
     * @return list<string>
     */
    public function tables(DatabaseConnection $connection, ?string $database, string $schema, bool $withViews): array
    {
        $target = $this->targets->open($connection, $database);

        return in_array($schema, $this->schemasOn($target, $connection), true) ? $this->tablesOn($target, $connection, $schema, $withViews) : [];
    }
}
