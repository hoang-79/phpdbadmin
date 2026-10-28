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
        return array_map(function (array $row): array {
            preg_match('/\b(BEFORE|AFTER|INSTEAD OF)\s+(.+?)\s+ON\s/i', $row['definition'], $match);

            return [
                'name' => $row['name'],
                'timing' => strtoupper($match[1] ?? ''),
                'event' => strtoupper($match[2] ?? ''),
                'definition' => $row['definition'],
            ];
        }, $this->records($connection->select(
            'select tg.tgname as name, pg_get_triggerdef(tg.oid, true) as definition
             from pg_trigger tg
             join pg_class rel on rel.oid = tg.tgrelid
             join pg_namespace nsp on nsp.oid = rel.relnamespace
             where not tg.tgisinternal and nsp.nspname = ? and rel.relname = ?
             order by tg.tgname',
            [$schema, $table],
        ), ['name', 'definition']));
    }

    public function referencingForeignKeys(Connection $connection, string $schema, string $table): array
    {
        $rules = ['a' => 'no action', 'r' => 'restrict', 'c' => 'cascade', 'n' => 'set null', 'd' => 'set default'];

        return array_map(fn (array $row): array => [
            'name' => $row['name'],
            'table' => $row['tbl'],
            'columns' => explode(',', $row['cols']),
            'foreignColumns' => explode(',', $row['refcols']),
            'onDelete' => $rules[$row['on_delete']] ?? $row['on_delete'],
        ], $this->records($connection->select(
            "select con.conname as name, rel.relname as tbl,
                    (select string_agg(a.attname, ',' order by k.ord) from unnest(con.conkey) with ordinality k(attnum, ord)
                       join pg_attribute a on a.attrelid = con.conrelid and a.attnum = k.attnum) as cols,
                    (select string_agg(a.attname, ',' order by k.ord) from unnest(con.confkey) with ordinality k(attnum, ord)
                       join pg_attribute a on a.attrelid = con.confrelid and a.attnum = k.attnum) as refcols,
                    con.confdeltype as on_delete
             from pg_constraint con
             join pg_class rel on rel.oid = con.conrelid
             join pg_class ref on ref.oid = con.confrelid
             join pg_namespace nsp on nsp.oid = ref.relnamespace
             where con.contype = 'f' and nsp.nspname = ? and ref.relname = ?
             order by rel.relname, con.conname",
            [$schema, $table],
        ), ['name', 'tbl', 'cols', 'refcols', 'on_delete']));
    }

    public function ddl(Connection $connection, string $schema, string $object, bool $isView): string
    {
        $grammar = $connection->getQueryGrammar();
        $qualified = $grammar->wrap($schema).'.'.$grammar->wrap($object);
        $oid = $connection->selectOne(
            'select c.oid from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relname = ?',
            [$schema, $object],
        )?->oid;

        if ($oid === null) {
            return '';
        }

        if ($isView) {
            return 'CREATE VIEW '.$qualified." AS\n".rtrim((string) $connection->selectOne('select pg_get_viewdef(?::oid, true) as v', [$oid])?->v);
        }

        $lines = [];
        foreach ($this->records($connection->select(
            "select a.attname as name, format_type(a.atttypid, a.atttypmod) as type, a.attnotnull::int as notnull,
                    coalesce(pg_get_expr(d.adbin, d.adrelid), '') as def, a.attidentity as identity, a.attgenerated as generated,
                    case when a.attcollation <> t.typcollation then co.collname else '' end as collation
             from pg_attribute a
             join pg_type t on t.oid = a.atttypid
             left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
             left join pg_collation co on co.oid = a.attcollation
             where a.attrelid = ?::oid and a.attnum > 0 and not a.attisdropped
             order by a.attnum",
            [$oid],
        ), ['name', 'type', 'notnull', 'def', 'identity', 'generated', 'collation']) as $column) {
            $line = '    '.$grammar->wrap($column['name']).' '.$column['type'];
            $line .= match ($column['identity']) {
                'a' => ' GENERATED ALWAYS AS IDENTITY',
                'd' => ' GENERATED BY DEFAULT AS IDENTITY',
                default => '',
            };
            $line .= $column['collation'] !== '' ? ' COLLATE '.$grammar->wrap($column['collation']) : '';
            $line .= match (true) {
                $column['generated'] === 's' => ' GENERATED ALWAYS AS ('.$column['def'].') STORED',
                $column['def'] !== '' => ' DEFAULT '.$column['def'],
                default => '',
            };
            $line .= $column['notnull'] === '1' ? ' NOT NULL' : '';
            $lines[] = $line;
        }

        foreach ($this->records($connection->select(
            "select conname as name, pg_get_constraintdef(oid, true) as def from pg_constraint
             where conrelid = ?::oid and contype in ('p', 'u', 'c', 'f', 'x')
             order by case contype when 'p' then 0 when 'u' then 1 when 'f' then 2 else 3 end, conname",
            [$oid],
        ), ['name', 'def']) as $constraint) {
            $lines[] = '    CONSTRAINT '.$grammar->wrap($constraint['name']).' '.$constraint['def'];
        }

        $statements = ['CREATE TABLE '.$qualified." (\n".implode(",\n", $lines)."\n);"];

        foreach ($this->strings($connection->select(
            'select pg_get_indexdef(i.indexrelid) as def from pg_index i
             where i.indrelid = ?::oid and not exists (select 1 from pg_constraint c where c.conindid = i.indexrelid)
             order by i.indexrelid',
            [$oid],
        ), 'def') as $index) {
            $statements[] = $index.';';
        }

        $comment = (string) $connection->selectOne("select coalesce(obj_description(?::oid, 'pg_class'), '') as c", [$oid])?->c;
        if ($comment !== '') {
            $statements[] = 'COMMENT ON TABLE '.$qualified.' IS '.$this->quote($comment).';';
        }

        foreach ($this->records($connection->select(
            'select a.attname as name, col_description(a.attrelid, a.attnum) as c from pg_attribute a
             where a.attrelid = ?::oid and a.attnum > 0 and not a.attisdropped and col_description(a.attrelid, a.attnum) is not null
             order by a.attnum',
            [$oid],
        ), ['name', 'c']) as $columnComment) {
            $statements[] = 'COMMENT ON COLUMN '.$qualified.'.'.$grammar->wrap($columnComment['name']).' IS '.$this->quote($columnComment['c']).';';
        }

        return implode("\n\n", $statements);
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
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
