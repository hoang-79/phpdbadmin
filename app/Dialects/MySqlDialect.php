<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use Illuminate\Database\Connection;
use PDO;
use Pdo\Mysql;
use Throwable;

/**
 * MySQL 8.0 und neuer. Verbindungsfehler nach Code (F-2): 2002 nicht erreichbar, 1045 Zugang, 1049 Datenbank.
 */
class MySqlDialect implements Dialect
{
    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::MySql;
    }

    public function connectionConfig(ConnectionSettings $settings, ?string $address, int $connectTimeout): array
    {
        $options = [PDO::ATTR_TIMEOUT => $connectTimeout];

        if ($settings->sslMode === SslMode::Require) {
            // Mit einer CA-Datei verlangt mysqlnd TLS; der Name im Zertifikat passt nicht zur geprüften Adresse.
            $options[Mysql::ATTR_SSL_CA] = (string) (openssl_get_cert_locations()['default_cert_file'] ?? '');
            $options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        return [
            'driver' => $this->driver()->value,
            'host' => $address,
            'port' => $settings->port(),
            'database' => $settings->database ?? '',
            'username' => $settings->username ?? '',
            'password' => $settings->password ?? '',
            'charset' => 'utf8mb4',
            'prefix' => '',
            'strict' => true,
            'timezone' => '+00:00',
            'options' => $options,
        ];
    }

    public function serverVersion(Connection $connection): string
    {
        $version = (string) $connection->selectOne('select version() as version')?->version;

        return preg_match('/^\d+(\.\d+)*/', $version, $match) === 1 ? $match[0] : $version;
    }

    public function describeConnectionError(Throwable $error): ConnectionProblem
    {
        $code = $this->errorCode($error);
        $message = $error->getMessage();

        $kind = match (true) {
            in_array($code, [1045, 1698], true) => ConnectionProblemKind::AccessDenied,
            in_array($code, [1044, 1049], true) => ConnectionProblemKind::DatabaseMissing,
            $code === 2005 => ConnectionProblemKind::HostUnknown,
            in_array($code, [2026, 3159], true), stripos($message, 'ssl') !== false => ConnectionProblemKind::Ssl,
            stripos($message, 'timed out') !== false => ConnectionProblemKind::Timeout,
            in_array($code, [2002, 2003, 2006, 2013], true) => ConnectionProblemKind::HostUnreachable,
            default => ConnectionProblemKind::Unknown,
        };

        return new ConnectionProblem($kind, $code !== null ? (string) $code : null, $message);
    }

    private function errorCode(Throwable $error): ?int
    {
        $info = property_exists($error, 'errorInfo') && is_array($error->errorInfo) ? $error->errorInfo : [];

        if (isset($info[1]) && is_numeric($info[1])) {
            return (int) $info[1];
        }

        if (preg_match('/\[(\d{4})\]/', $error->getMessage(), $match) === 1) {
            return (int) $match[1];
        }

        return is_numeric($error->getCode()) && (int) $error->getCode() > 0 ? (int) $error->getCode() : null;
    }
}
