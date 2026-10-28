<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * PostgreSQL 14 und neuer. Alle Verbindungsfehler tragen SQLSTATE 08006 — zugeordnet wird über den Meldungstext
 * (F-2).
 */
class PostgresDialect implements Dialect
{
    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::Postgres;
    }

    public function connectionConfig(ConnectionSettings $settings, ?string $address, int $connectTimeout): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $address,
            'port' => $settings->port(),
            'database' => $settings->database ?? 'postgres',
            'username' => $settings->username ?? '',
            'password' => $settings->password ?? '',
            'charset' => 'utf8',
            'prefix' => '',
            'sslmode' => $settings->sslMode->value,
            'timezone' => 'UTC',
            'options' => [PDO::ATTR_TIMEOUT => $connectTimeout],
        ];
    }

    public function serverVersion(Connection $connection): string
    {
        $version = (string) $connection->selectOne('show server_version')?->server_version;

        return preg_match('/^\d+(\.\d+)*/', $version, $match) === 1 ? $match[0] : $version;
    }

    public function describeConnectionError(Throwable $error): ConnectionProblem
    {
        $message = $error->getMessage();

        $kind = match (true) {
            str_contains($message, 'password authentication failed'),
            (bool) preg_match('/role ".*" does not exist/', $message),
            str_contains($message, 'no pg_hba.conf entry') => ConnectionProblemKind::AccessDenied,
            (bool) preg_match('/database ".*" does not exist/', $message) => ConnectionProblemKind::DatabaseMissing,
            stripos($message, 'ssl') !== false => ConnectionProblemKind::Ssl,
            str_contains($message, 'timeout expired') => ConnectionProblemKind::Timeout,
            str_contains($message, 'could not translate host name') => ConnectionProblemKind::HostUnknown,
            str_contains($message, 'Connection refused'),
            str_contains($message, 'could not connect'),
            str_contains($message, 'No route to host') => ConnectionProblemKind::HostUnreachable,
            default => ConnectionProblemKind::Unknown,
        };

        $state = property_exists($error, 'errorInfo') && is_array($error->errorInfo) ? ($error->errorInfo[0] ?? null) : null;
        $state ??= preg_match('/SQLSTATE\[(\w{5})\]/', $message, $match) === 1 ? $match[1] : null;

        return new ConnectionProblem($kind, is_string($state) ? $state : null, $message);
    }
}
