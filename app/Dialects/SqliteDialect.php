<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Throwable;

/**
 * SQLite 3.35 und neuer: eine Datei in einem erlaubten Ordner (V-05). Kein Server, keine Zugangsdaten.
 */
class SqliteDialect implements Dialect
{
    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::Sqlite;
    }

    public function connectionConfig(ConnectionSettings $settings, ?string $address, int $connectTimeout): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $address,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => $connectTimeout * 1000,
        ];
    }

    public function serverVersion(Connection $connection): string
    {
        // Liest den Kopf der Datei: sonst fiele «keine Datenbank» erst bei der ersten echten Abfrage auf.
        $connection->selectOne('select count(*) as tables from sqlite_master');

        return (string) $connection->selectOne('select sqlite_version() as version')?->version;
    }

    public function describeConnectionError(Throwable $error): ConnectionProblem
    {
        $message = $error->getMessage();

        $kind = match (true) {
            str_contains($message, 'file is not a database'), str_contains($message, 'file is encrypted') => ConnectionProblemKind::FileNotDatabase,
            str_contains($message, 'unable to open database file') => ConnectionProblemKind::FileMissing,
            default => ConnectionProblemKind::Unknown,
        };

        return new ConnectionProblem($kind, null, $message);
    }
}
