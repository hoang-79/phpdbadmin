<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Closure;
use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * PostgreSQL 14 und neuer. Alle Verbindungsfehler tragen SQLSTATE 08006 — zugeordnet wird über den Meldungstext
 * (F-2). Zeitlimit über statement_timeout (Fehler 57014).
 */
class PostgresDialect extends BaseDialect
{
    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::Postgres;
    }

    public function connectionConfig(ConnectionSettings $settings, ?string $address, int $connectTimeout, bool $readOnly = false): array
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

    public function prepareSession(Connection $connection, bool $readOnly): void
    {
        if ($readOnly) {
            $connection->statement('set session characteristics as transaction read only');
        }
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

        [$state] = $this->errorCodes($error);

        return new ConnectionProblem($kind, $state, $message);
    }

    public function checkConstraints(Connection $connection, string $schema, string $table): array
    {
        return $this->constraints($connection->select(
            "select con.conname as name, pg_get_constraintdef(con.oid) as definition
             from pg_constraint con
             join pg_class rel on rel.oid = con.conrelid
             join pg_namespace nsp on nsp.oid = rel.relnamespace
             where con.contype = 'c' and nsp.nspname = ? and rel.relname = ?
             order by con.conname",
            [$schema, $table],
        ));
    }

    public function triggers(Connection $connection, string $schema, string $table): array
    {
        return $this->strings($connection->select(
            'select tg.tgname as name
             from pg_trigger tg
             join pg_class rel on rel.oid = tg.tgrelid
             join pg_namespace nsp on nsp.oid = rel.relnamespace
             where not tg.tgisinternal and nsp.nspname = ? and rel.relname = ?
             order by tg.tgname',
            [$schema, $table],
        ), 'name');
    }

    public function routines(Connection $connection, string $schema): array
    {
        // Ohne Funktionen, die zu Erweiterungen gehören (pg_depend «e») — die hat niemand selbst geschrieben.
        return array_map(fn (array $row): array => [
            'name' => $row['name'],
            'kind' => $row['kind'] === 'p' ? 'procedure' : 'function',
        ], $this->records($connection->select(
            "select p.proname as name, p.prokind as kind
             from pg_proc p
             join pg_namespace n on n.oid = p.pronamespace
             where n.nspname = ? and p.prokind in ('f', 'p')
               and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e')
             order by p.proname",
            [$schema],
        ), ['name', 'kind']));
    }

    public function schemaTriggers(Connection $connection, string $schema): array
    {
        return array_map(fn (array $row): array => ['name' => $row['name'], 'table' => $row['tbl']], $this->records($connection->select(
            'select tg.tgname as name, rel.relname as tbl
             from pg_trigger tg
             join pg_class rel on rel.oid = tg.tgrelid
             join pg_namespace nsp on nsp.oid = rel.relnamespace
             where not tg.tgisinternal and nsp.nspname = ?
             order by tg.tgname',
            [$schema],
        ), ['name', 'tbl']));
    }

    public function events(Connection $connection, string $schema): ?array
    {
        return null;
    }

    public function sequences(Connection $connection, string $schema): array
    {
        return $this->strings($connection->select(
            "select c.relname as name from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relkind = 'S' order by c.relname",
            [$schema],
        ), 'name');
    }

    public function supportsStatementTimeout(): bool
    {
        return true;
    }

    public function setStatementTimeout(Connection $connection, int $milliseconds): void
    {
        $connection->statement('set statement_timeout = '.max(0, $milliseconds));
    }

    public function isTimeout(Throwable $error): bool
    {
        [$state] = $this->errorCodes($error);

        return $state === '57014';
    }

    public function runReadOnly(Connection $connection, Closure $statement): mixed
    {
        $connection->beginTransaction();

        try {
            // Innerhalb der Transaktion: ein späteres «SET TRANSACTION READ WRITE» scheitert an 25001 (F-7).
            $connection->statement('set transaction read only');

            return $statement();
        } finally {
            try {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
                // COMMIT des Menschen hat die Transaktion schon beendet.
            }
        }
    }

    public function isReadOnlyViolation(Throwable $error): bool
    {
        [$state] = $this->errorCodes($error);

        return in_array($state, ['25006', '25001'], true);
    }

    public function accountWarnings(Connection $connection): array
    {
        $role = $connection->selectOne(
            "select rolsuper as superuser, pg_has_role(current_user, 'pg_read_server_files', 'MEMBER') as files from pg_roles where rolname = current_user",
        );

        $warnings = [];

        if ((bool) ($role->superuser ?? false)) {
            $warnings[] = [
                'key' => 'superuser',
                'title' => 'Dieses Konto ist Superuser.',
                'recommendation' => 'Empfehlung: ein eigenes Konto ohne Superuser-Recht — für Produktion nur mit Leserechten.',
            ];
        }

        if ((bool) ($role->superuser ?? false) || (bool) ($role->files ?? false)) {
            $warnings[] = [
                'key' => 'file_read',
                'title' => 'Dieses Konto darf Dateien auf dem Server lesen.',
                'recommendation' => 'Empfehlung: ein eigenes Konto ohne pg_read_server_files.',
            ];
        }

        return $warnings;
    }

    protected function rowEstimates(Connection $connection, string $schema): array
    {
        $estimates = [];

        foreach ($connection->select(
            "select c.relname as name, c.reltuples as estimate
             from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = ? and c.relkind in ('r', 'p')",
            [$schema],
        ) as $row) {
            // -1: noch nie analysiert — keine Schätzung statt einer falschen.
            if ((float) $row->estimate >= 0) {
                $estimates[(string) $row->name] = (int) $row->estimate;
            }
        }

        return $estimates;
    }
}
