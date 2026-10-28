<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Enums\SslMode;
use Closure;
use Illuminate\Database\Connection;
use PDO;
use Pdo\Mysql;
use Throwable;

/**
 * MySQL 8.0 und neuer. Verbindungsfehler nach Code (F-2): 2002 nicht erreichbar, 1045 Zugang, 1049 Datenbank.
 * Zeitlimit über max_execution_time — gilt nur für SELECT und bricht SLEEP still ab (F-2).
 */
class MySqlDialect extends BaseDialect
{
    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::MySql;
    }

    public function connectionConfig(ConnectionSettings $settings, ?string $address, int $connectTimeout, bool $readOnly = false): array
    {
        $options = [
            PDO::ATTR_TIMEOUT => $connectTimeout,
            // Nie mehrere Anweisungen in einem Aufruf (F-7) und nie Dateien des App-Servers hochladen.
            Mysql::ATTR_MULTI_STATEMENTS => false,
            Mysql::ATTR_LOCAL_INFILE => false,
        ];

        if ($readOnly) {
            $options[Mysql::ATTR_INIT_COMMAND] = 'SET SESSION TRANSACTION READ ONLY';
        }

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
        [, $code] = $this->errorCodes($error);
        $message = $error->getMessage();

        if ($code === null && preg_match('/\[(\d{4})\]/', $message, $match) === 1) {
            $code = (int) $match[1];
        }

        $code ??= is_numeric($error->getCode()) && (int) $error->getCode() > 0 ? (int) $error->getCode() : null;

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

    public function checkConstraints(Connection $connection, string $schema, string $table): array
    {
        return $this->constraints($connection->select(
            'select cc.constraint_name as name, cc.check_clause as definition
             from information_schema.check_constraints cc
             join information_schema.table_constraints tc
               on tc.constraint_schema = cc.constraint_schema and tc.constraint_name = cc.constraint_name
             where tc.table_schema = ? and tc.table_name = ? and tc.constraint_type = ?
             order by cc.constraint_name',
            [$schema, $table, 'CHECK'],
        ));
    }

    public function triggers(Connection $connection, string $schema, string $table): array
    {
        return $this->strings($connection->select(
            'select trigger_name as name from information_schema.triggers where trigger_schema = ? and event_object_table = ? order by trigger_name',
            [$schema, $table],
        ), 'name');
    }

    public function supportsStatementTimeout(): bool
    {
        return true;
    }

    public function setStatementTimeout(Connection $connection, int $milliseconds): void
    {
        $connection->statement('set session max_execution_time = '.max(0, $milliseconds));
    }

    public function isTimeout(Throwable $error): bool
    {
        [, $code] = $this->errorCodes($error);

        return $code === 3024;
    }

    public function runReadOnly(Connection $connection, Closure $statement): mixed
    {
        // Jede Anweisung erneut: ein «SET SESSION TRANSACTION READ WRITE» des Menschen gilt so nie weiter (F-7).
        $connection->statement('set session transaction read only');
        $connection->beginTransaction();

        try {
            return $statement();
        } finally {
            $this->rollBackQuietly($connection);
        }
    }

    public function isReadOnlyViolation(Throwable $error): bool
    {
        [$state, $code] = $this->errorCodes($error);

        return $state === '25006' || in_array($code, [1792, 1568], true);
    }

    public function accountWarnings(Connection $connection): array
    {
        $grants = array_map(
            fn (object $row): string => (string) (array_values((array) $row)[0] ?? ''),
            $connection->select('show grants'),
        );
        $global = array_values(array_filter($grants, fn (string $grant): bool => (bool) preg_match('/ON \*\.\* TO/i', $grant)));

        $all = array_any($global, fn (string $grant): bool => (bool) preg_match('/\bALL PRIVILEGES\b|\bSUPER\b/i', $grant));
        $file = $all || array_any($global, fn (string $grant): bool => (bool) preg_match('/\bFILE\b/i', $grant));
        // NULL: LOAD_FILE und INTO OUTFILE sind auf dem Server ganz abgeschaltet.
        $secureFilePriv = $connection->selectOne('select @@secure_file_priv as folder')?->folder;

        $warnings = [];

        if ($all) {
            $warnings[] = [
                'key' => 'superuser',
                'title' => 'Dieses Konto hat alle Rechte auf dem Server.',
                'recommendation' => 'Empfehlung: ein eigenes Konto nur mit Rechten auf die nötige Datenbank — für Produktion nur SELECT.',
            ];
        }

        if ($file && $secureFilePriv !== null) {
            $warnings[] = [
                'key' => 'file_read',
                'title' => 'Dieses Konto darf Dateien auf dem Server lesen.',
                'recommendation' => 'Empfehlung: ein eigenes Konto ohne FILE-Recht'.($secureFilePriv === '' ? ' — secure_file_priv ist leer, also jeder Ordner.' : '.'),
            ];
        }

        return $warnings;
    }

    protected function rowEstimates(Connection $connection, string $schema): array
    {
        $estimates = [];

        foreach ($connection->select(
            'select table_name as name, table_rows as estimate from information_schema.tables where table_schema = ? and table_type = ?',
            [$schema, 'BASE TABLE'],
        ) as $row) {
            if ($row->estimate !== null) {
                $estimates[(string) $row->name] = (int) $row->estimate;
            }
        }

        return $estimates;
    }

    private function rollBackQuietly(Connection $connection): void
    {
        try {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // COMMIT oder DDL des Menschen hat die Transaktion schon beendet.
        }
    }
}
