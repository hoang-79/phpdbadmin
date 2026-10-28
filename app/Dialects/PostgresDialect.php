<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Enums\MaintenanceOperation;
use App\Enums\PrivilegeLevel;
use Closure;
use Illuminate\Database\Connection;
use InvalidArgumentException;
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
            // Erkennbar in pg_stat_activity (W-02).
            'application_name' => 'phpdbadmin',
            'options' => [PDO::ATTR_TIMEOUT => $connectTimeout],
        ];
    }

    public function prepareSession(Connection $connection, bool $readOnly): void
    {
        if ($readOnly) {
            $connection->statement('set session characteristics as transaction read only');
        }
    }

    public function databases(Connection $connection): array
    {
        return $this->strings($connection->select(
            'select datname from pg_database where not datistemplate and datallowconn order by datname',
        ), 'datname');
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

    public function serverOverview(Connection $connection): array
    {
        $server = (array) $connection->selectOne(
            "select extract(epoch from now() - pg_postmaster_start_time())::bigint as uptime,
                    extract(epoch from pg_postmaster_start_time())::bigint as started,
                    current_setting('server_encoding') as charset,
                    current_setting('max_connections') as max_connections,
                    current_setting('shared_buffers') as shared_buffers,
                    (select datcollate from pg_database where datname = 'template1') as collation,
                    (select count(*) from pg_stat_activity where backend_type = 'client backend') as sessions",
        );

        $databases = array_map(fn (array $row): array => [
            'name' => $row['name'],
            'size' => $row['size'] !== '' ? (int) $row['size'] : null,
            'charset' => $row['charset'] !== '' ? $row['charset'] : null,
            'collation' => $row['collation'] !== '' ? $row['collation'] : null,
        ], $this->records($connection->select(
            "select datname as name, pg_encoding_to_char(encoding) as charset, datcollate as collation,
                    case when has_database_privilege(oid, 'CONNECT') then pg_database_size(oid) end as size
             from pg_database where not datistemplate and datallowconn order by datname",
        ), ['name', 'charset', 'collation', 'size']));

        // Schemas der geöffneten Datenbank — dieselben wie im Navigator.
        $visible = $this->schemas($connection);
        $schemas = array_values(array_filter(array_map(fn (array $row): array => [
            'name' => $row['name'],
            'size' => (int) $row['size'],
        ], $this->records($connection->select(
            "select n.nspname as name, coalesce(sum(pg_total_relation_size(c.oid)), 0) as size
             from pg_namespace n left join pg_class c on c.relnamespace = n.oid and c.relkind in ('r', 'm', 'p')
             group by n.nspname order by n.nspname",
        ), ['name', 'size'])), fn (array $schema): bool => in_array($schema['name'], $visible, true)));

        return [
            'version' => $this->serverVersion($connection),
            'uptime' => isset($server['uptime']) ? (int) $server['uptime'] : null,
            'charset' => isset($server['charset']) ? (string) $server['charset'] : null,
            'collation' => isset($server['collation']) ? (string) $server['collation'] : null,
            'facts' => [
                ['key' => 'sessions', 'value' => (int) ($server['sessions'] ?? 0), 'kind' => 'number'],
                ['key' => 'max_connections', 'value' => (int) ($server['max_connections'] ?? 0), 'kind' => 'number'],
                ['key' => 'shared_buffers', 'value' => (string) ($server['shared_buffers'] ?? ''), 'kind' => 'text'],
                ['key' => 'started_at', 'value' => gmdate('Y-m-d\TH:i:s\Z', (int) ($server['started'] ?? 0)), 'kind' => 'datetime'],
            ],
            'databases' => $databases,
            'schemas' => $schemas,
        ];
    }

    public function sessionId(Connection $connection): int
    {
        return (int) $connection->selectOne('select pg_backend_pid() as id')?->id;
    }

    /** Nur Sitzungen von Clients — Hintergrundprozesse (Autovacuum, Checkpointer) beendet man nicht von hier. */
    public function processes(Connection $connection): array
    {
        $own = $this->sessionId($connection);

        return array_map(function (array $row) use ($own): array {
            $id = (int) $row['pid'];
            $text = fn (string $key): ?string => $row[$key] !== '' ? $row[$key] : null;

            return [
                'id' => $id,
                'user' => $row['usename'],
                'host' => $row['addr'] !== '' ? $row['addr'].($row['port'] !== '' ? ':'.$row['port'] : '') : null,
                'program' => $text('application_name'),
                'database' => $text('datname'),
                'command' => $text('state'),
                'state' => $row['wait_event'] !== '' ? $row['wait_event_type'].': '.$row['wait_event'] : null,
                'seconds' => $row['seconds'] !== '' ? (int) $row['seconds'] : null,
                'statement' => $this->shortStatement($row['query']),
                'own' => $id === $own,
            ];
        }, $this->records($connection->select(
            "select pid, usename, application_name, host(client_addr) as addr, client_port as port, datname, state,
                    wait_event_type, wait_event, extract(epoch from now() - coalesce(state_change, backend_start))::bigint as seconds, query
             from pg_stat_activity where backend_type = 'client backend' order by pid",
        ), ['pid', 'usename', 'application_name', 'addr', 'port', 'datname', 'state', 'wait_event_type', 'wait_event', 'seconds', 'query']));
    }

    public function endProcessStatement(int $id, bool $wholeSession): PlannedStatement
    {
        return new PlannedStatement('SELECT '.($wholeSession ? 'pg_terminate_backend' : 'pg_cancel_backend').'('.$id.')');
    }

    public function endProcess(Connection $connection, int $id, bool $wholeSession): bool
    {
        return (bool) $connection->selectOne($this->endProcessStatement($id, $wholeSession)->sql.' as ended')?->ended;
    }

    public function variables(Connection $connection): array
    {
        return $this->settingRows(
            $connection->select('select name, current_setting(name) as value, short_desc as description from pg_settings order by name'),
            'name',
            'value',
            'description',
        );
    }

    public function statusValues(Connection $connection): array
    {
        $values = [];

        foreach ((array) $connection->selectOne('select * from pg_stat_database where datname = current_database()') as $name => $value) {
            if (! in_array($name, ['datid', 'datname'], true)) {
                $values[] = ['name' => 'pg_stat_database.'.$name, 'value' => (string) $value, 'description' => null];
            }
        }

        foreach ($connection->select(
            "select coalesce(state, 'unknown') as state, count(*) as sessions from pg_stat_activity where backend_type = 'client backend' group by 1 order by 1",
        ) as $row) {
            $values[] = ['name' => 'pg_stat_activity.'.$row->state, 'value' => (string) $row->sessions, 'description' => null];
        }

        return $values;
    }

    public function accountsHaveHosts(): bool
    {
        return false;
    }

    /** Rollen ohne die vordefinierten (pg_*); Rechte ohne die des Besitzers, die ohnehin gelten. */
    public function accounts(Connection $connection): array
    {
        $grants = [];

        foreach ($connection->select(
            'select r.rolname as grantee, m.rolname as role from pg_auth_members am
             join pg_roles r on r.oid = am.member join pg_roles m on m.oid = am.roleid order by m.rolname',
        ) as $row) {
            $grants[(string) $row->grantee][] = 'GRANT '.$this->quoteIdentifier((string) $row->role);
        }

        foreach ($this->records($connection->select(
            "select r.rolname as grantee, 'DATABASE' as kind, d.datname as object, string_agg(a.privilege_type, ', ' order by a.privilege_type) as privileges
             from pg_database d cross join lateral aclexplode(d.datacl) a join pg_roles r on r.oid = a.grantee
             where not d.datistemplate and a.grantee <> d.datdba group by r.rolname, d.datname
             union all
             select r.rolname, 'SCHEMA', n.nspname, string_agg(a.privilege_type, ', ' order by a.privilege_type)
             from pg_namespace n cross join lateral aclexplode(n.nspacl) a join pg_roles r on r.oid = a.grantee
             where n.nspname !~ '^pg_' and n.nspname <> 'information_schema' and a.grantee <> n.nspowner group by r.rolname, n.nspname
             order by 1, 2, 3",
        ), ['grantee', 'kind', 'object', 'privileges']) as $row) {
            $grants[$row['grantee']][] = 'GRANT '.$row['privileges'].' ON '.$row['kind'].' '.$this->quoteIdentifier($row['object']);
        }

        foreach ($this->records($connection->select(
            "select r.rolname as grantee, n.nspname as schema, c.relname as tbl, string_agg(a.privilege_type, ', ' order by a.privilege_type) as privileges
             from pg_class c join pg_namespace n on n.oid = c.relnamespace
             cross join lateral aclexplode(c.relacl) a join pg_roles r on r.oid = a.grantee
             where c.relkind in ('r', 'v', 'm', 'p', 'f') and n.nspname !~ '^pg_' and n.nspname <> 'information_schema' and a.grantee <> c.relowner
             group by r.rolname, n.nspname, c.relname order by 1, 2, 3",
        ), ['grantee', 'schema', 'tbl', 'privileges']) as $row) {
            $grants[$row['grantee']][] = 'GRANT '.$row['privileges'].' ON TABLE '.$this->quoteIdentifier($row['schema']).'.'.$this->quoteIdentifier($row['tbl']);
        }

        $current = $this->currentAccount($connection);
        $attributes = ['rolsuper' => 'superuser', 'rolcanlogin' => 'login', 'rolcreatedb' => 'createdb', 'rolcreaterole' => 'createrole', 'rolreplication' => 'replication', 'rolbypassrls' => 'bypassrls'];

        return array_values(array_map(function (object $row) use ($grants, $current, $attributes): array {
            $values = (array) $row;
            $name = (string) $values['name'];

            return [
                'name' => $name,
                'host' => null,
                'attributes' => array_values(array_map(
                    fn (string $column): string => $attributes[$column],
                    array_filter(array_keys($attributes), fn (string $column): bool => (bool) $values[$column]),
                )),
                'grants' => $grants[$name] ?? [],
                'own' => $current->name === $name,
            ];
        }, $connection->select(
            "select rolname as name, rolsuper, rolcanlogin, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls
             from pg_roles where rolname !~ '^pg_' order by rolname",
        )));
    }

    public function currentAccount(Connection $connection): AccountName
    {
        return new AccountName((string) $connection->selectOne('select current_user as name')?->name);
    }

    /** Auf dem Server: Mitgliedschaft in vordefinierten Rollen — PostgreSQL kennt keine Rechte auf «*.*». */
    public function privilegeChoices(): array
    {
        return [
            PrivilegeLevel::Server->value => ['pg_read_all_data', 'pg_write_all_data', 'pg_monitor', 'pg_read_all_settings', 'pg_read_all_stats', 'pg_stat_scan_tables', 'pg_signal_backend'],
            PrivilegeLevel::Database->value => ['CONNECT', 'CREATE', 'TEMPORARY', 'ALL PRIVILEGES'],
            // USAGE und CREATE gelten dem Schema, die übrigen allen Tabellen darin.
            PrivilegeLevel::Schema->value => ['USAGE', 'CREATE', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'],
            PrivilegeLevel::Table->value => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER', 'ALL PRIVILEGES'],
        ];
    }

    public function createAccountStatements(Connection $connection, AccountName $account, string $password): array
    {
        $sql = 'CREATE ROLE '.$this->quoteIdentifier($account->name).' LOGIN PASSWORD ';

        return [new PlannedStatement($sql.$this->quoteString($connection, $password), $sql.PlannedStatement::MASK)];
    }

    public function dropAccountStatements(Connection $connection, AccountName $account): array
    {
        return [new PlannedStatement('DROP ROLE '.$this->quoteIdentifier($account->name))];
    }

    public function privilegeStatements(Connection $connection, bool $grant, AccountName $account, PrivilegeTarget $target, array $privileges): array
    {
        $privileges = $this->allowedPrivileges($target->level, $privileges);
        $statement = fn (array $chosen, string $on): PlannedStatement => new PlannedStatement(
            ($grant ? 'GRANT ' : 'REVOKE ').implode(', ', $chosen).$on.($grant ? ' TO ' : ' FROM ').$this->quoteIdentifier($account->name),
        );

        return match ($target->level) {
            PrivilegeLevel::Server => [$statement(array_map($this->quoteIdentifier(...), $privileges), '')],
            PrivilegeLevel::Database => [$statement($privileges, ' ON DATABASE '.$this->quoteIdentifier((string) $target->database))],
            PrivilegeLevel::Schema => array_values(array_filter([
                ($onSchema = array_values(array_intersect($privileges, ['USAGE', 'CREATE']))) !== []
                    ? $statement($onSchema, ' ON SCHEMA '.$this->quoteIdentifier((string) $target->schema)) : null,
                ($onTables = array_values(array_diff($privileges, ['USAGE', 'CREATE']))) !== []
                    ? $statement($onTables, ' ON ALL TABLES IN SCHEMA '.$this->quoteIdentifier((string) $target->schema)) : null,
            ])),
            PrivilegeLevel::Table => [$statement($privileges, ' ON TABLE '.$this->quoteIdentifier((string) $target->schema).'.'.$this->quoteIdentifier((string) $target->table))],
        };
    }

    public function maintenanceOperations(): array
    {
        return [MaintenanceOperation::Analyze, MaintenanceOperation::Vacuum];
    }

    public function maintenanceForTables(MaintenanceOperation $operation): bool
    {
        return true;
    }

    public function maintenanceStatements(Connection $connection, MaintenanceOperation $operation, string $schema, ?array $tables): array
    {
        $keyword = match ($operation) {
            MaintenanceOperation::Analyze => 'ANALYZE ',
            MaintenanceOperation::Vacuum => 'VACUUM ',
            MaintenanceOperation::Optimize, MaintenanceOperation::Check => throw new InvalidArgumentException('PostgreSQL kennt dafür ANALYZE und VACUUM.'),
        };
        $tables ??= $this->baseTables($connection, $schema);

        if ($tables === []) {
            return [];
        }

        $names = array_map(fn (string $table): string => $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table), $tables);

        return [new PlannedStatement($keyword.implode(', ', $names), objects: $tables)];
    }

    /** ANALYZE und VACUUM melden nichts — gelingt die Anweisung, gilt sie für jede genannte Tabelle. */
    public function runMaintenance(Connection $connection, PlannedStatement $statement): array
    {
        $connection->statement($statement->sql);

        return array_map(fn (string $table): array => ['table' => $table, 'status' => 'ok', 'message' => 'OK'], $statement->objects);
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
