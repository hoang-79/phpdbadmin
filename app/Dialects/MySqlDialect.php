<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Enums\MaintenanceOperation;
use App\Enums\PrivilegeLevel;
use App\Enums\SslMode;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

/**
 * MySQL 8.0 und neuer. Verbindungsfehler nach Code (F-2): 2002 nicht erreichbar, 1045 Zugang, 1049 Datenbank.
 * Zeitlimit über max_execution_time — gilt nur für SELECT und bricht SLEEP still ab (F-2).
 */
class MySqlDialect extends BaseDialect
{
    /** Schemas des Systems — wie Laravels getSchemas() sie auslässt. */
    private const array SYSTEM_SCHEMAS = ['information_schema', 'mysql', 'ndbinfo', 'performance_schema', 'sys'];

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
        return array_map(fn (array $row): array => [
            'name' => $row['name'],
            'timing' => strtoupper($row['timing']),
            'event' => strtoupper($row['event']),
            'definition' => $row['definition'],
        ], $this->records($connection->select(
            'select trigger_name as name, action_timing as timing, event_manipulation as event, action_statement as definition
             from information_schema.triggers where trigger_schema = ? and event_object_table = ? order by trigger_name',
            [$schema, $table],
        ), ['name', 'timing', 'event', 'definition']));
    }

    /**
     * Nur Verweise aus demselben Schema: MariaDB öffnet für information_schema sonst jede Tabelle jeder Datenbank
     * (gemessen über 15 s auf einem Server mit vielen Datenbanken); mit festem table_schema nur die eine.
     */
    public function referencingForeignKeys(Connection $connection, string $schema, string $table): array
    {
        return array_map(fn (array $row): array => [
            'name' => $row['name'],
            'table' => $row['tbl'],
            'columns' => explode(',', $row['cols']),
            'foreignColumns' => explode(',', $row['refcols']),
            'onDelete' => strtolower($row['on_delete']),
        ], $this->records($connection->select(
            'select kcu.constraint_name as name, kcu.table_name as tbl,
                    group_concat(kcu.column_name order by kcu.ordinal_position) as cols,
                    group_concat(kcu.referenced_column_name order by kcu.ordinal_position) as refcols,
                    rc.delete_rule as on_delete
             from information_schema.key_column_usage kcu
             join information_schema.referential_constraints rc
               on rc.constraint_schema = kcu.constraint_schema and rc.constraint_name = kcu.constraint_name and rc.table_name = kcu.table_name
             where kcu.table_schema = ? and kcu.referenced_table_schema = ? and kcu.referenced_table_name = ?
               and rc.constraint_schema = ?
             group by kcu.constraint_name, kcu.table_name, rc.delete_rule
             order by kcu.table_name, kcu.constraint_name',
            [$schema, $schema, $table, $schema],
        ), ['name', 'tbl', 'cols', 'refcols', 'on_delete']));
    }

    public function ddl(Connection $connection, string $schema, string $object, bool $isView): string
    {
        $name = $connection->getQueryGrammar()->wrap($schema.'.'.$object);
        $row = (array) $connection->selectOne(($isView ? 'show create view ' : 'show create table ').$name);

        return (string) ($row[$isView ? 'Create View' : 'Create Table'] ?? '');
    }

    public function routines(Connection $connection, string $schema): array
    {
        return array_map(fn (array $row): array => [
            'name' => $row['name'],
            'kind' => strtoupper($row['kind']) === 'PROCEDURE' ? 'procedure' : 'function',
        ], $this->records($connection->select(
            'select routine_name as name, routine_type as kind from information_schema.routines where routine_schema = ? order by routine_name',
            [$schema],
        ), ['name', 'kind']));
    }

    public function schemaTriggers(Connection $connection, string $schema): array
    {
        return array_map(fn (array $row): array => ['name' => $row['name'], 'table' => $row['tbl']], $this->records($connection->select(
            'select trigger_name as name, event_object_table as tbl from information_schema.triggers where trigger_schema = ? order by trigger_name',
            [$schema],
        ), ['name', 'tbl']));
    }

    public function events(Connection $connection, string $schema): array
    {
        return $this->strings($connection->select(
            'select event_name as name from information_schema.events where event_schema = ? order by event_name',
            [$schema],
        ), 'name');
    }

    public function sequences(Connection $connection, string $schema): ?array
    {
        return null;
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
                'title' => __('Dieses Konto hat alle Rechte auf dem Server.', locale: 'de'),
                'recommendation' => __('Empfehlung: ein eigenes Konto nur mit Rechten auf die nötige Datenbank — für Produktion nur SELECT.', locale: 'de'),
            ];
        }

        if ($file && $secureFilePriv !== null) {
            $warnings[] = [
                'key' => 'file_read',
                'title' => __('Dieses Konto darf Dateien auf dem Server lesen.', locale: 'de'),
                'recommendation' => $secureFilePriv === ''
                    ? __('Empfehlung: ein eigenes Konto ohne FILE-Recht — secure_file_priv ist leer, also jeder Ordner.', locale: 'de')
                    : __('Empfehlung: ein eigenes Konto ohne FILE-Recht.', locale: 'de'),
            ];
        }

        return $warnings;
    }

    public function serverOverview(Connection $connection): array
    {
        $variables = $this->keyValues($connection->select(
            "show global variables where variable_name in ('version_comment', 'character_set_server', 'collation_server', 'max_connections', 'default_storage_engine', 'system_time_zone', 'innodb_buffer_pool_size')",
        ));
        $status = $this->keyValues($connection->select(
            "show global status where variable_name in ('Uptime', 'Threads_connected', 'Questions')",
        ));

        $sizes = [];
        foreach ($connection->select(
            'select table_schema as name, sum(data_length + index_length) as size from information_schema.tables
             where table_schema not in (?, ?, ?, ?, ?) group by table_schema',
            self::SYSTEM_SCHEMAS,
        ) as $row) {
            $sizes[(string) $row->name] = $row->size !== null ? (int) $row->size : null;
        }

        $databases = array_map(fn (array $row): array => [
            'name' => $row['name'],
            'size' => $sizes[$row['name']] ?? 0,
            'charset' => $row['charset'] !== '' ? $row['charset'] : null,
            'collation' => $row['collation'] !== '' ? $row['collation'] : null,
        ], $this->records($connection->select(
            'select schema_name as name, default_character_set_name as charset, default_collation_name as collation
             from information_schema.schemata where schema_name not in (?, ?, ?, ?, ?) order by schema_name',
            self::SYSTEM_SCHEMAS,
        ), ['name', 'charset', 'collation']));

        $number = fn (array $values, string $key): ?int => isset($values[$key]) && is_numeric($values[$key]) ? (int) $values[$key] : null;

        return [
            'version' => $this->serverVersion($connection),
            'uptime' => $number($status, 'uptime'),
            'charset' => $variables['character_set_server'] ?? null,
            'collation' => $variables['collation_server'] ?? null,
            'facts' => array_values(array_filter([
                isset($variables['version_comment']) ? ['key' => 'version_comment', 'value' => $variables['version_comment'], 'kind' => 'text'] : null,
                isset($variables['default_storage_engine']) ? ['key' => 'default_storage_engine', 'value' => $variables['default_storage_engine'], 'kind' => 'text'] : null,
                $number($status, 'threads_connected') !== null ? ['key' => 'threads_connected', 'value' => (int) $number($status, 'threads_connected'), 'kind' => 'number'] : null,
                $number($variables, 'max_connections') !== null ? ['key' => 'max_connections', 'value' => (int) $number($variables, 'max_connections'), 'kind' => 'number'] : null,
                $number($status, 'questions') !== null ? ['key' => 'questions', 'value' => (int) $number($status, 'questions'), 'kind' => 'number'] : null,
                $number($variables, 'innodb_buffer_pool_size') !== null ? ['key' => 'innodb_buffer_pool_size', 'value' => (int) $number($variables, 'innodb_buffer_pool_size'), 'kind' => 'bytes'] : null,
                isset($variables['system_time_zone']) ? ['key' => 'system_time_zone', 'value' => $variables['system_time_zone'], 'kind' => 'text'] : null,
            ])),
            'databases' => $databases,
            'schemas' => null,
        ];
    }

    public function sessionId(Connection $connection): int
    {
        return (int) $connection->selectOne('select connection_id() as id')?->id;
    }

    public function processes(Connection $connection): array
    {
        $own = $this->sessionId($connection);

        return array_values(array_map(function (object $row) use ($own): array {
            $values = array_change_key_case((array) $row);
            $id = (int) $values['id'];
            $text = fn (string $key): ?string => isset($values[$key]) && $values[$key] !== '' ? (string) $values[$key] : null;

            return [
                'id' => $id,
                'user' => (string) ($values['user'] ?? ''),
                'host' => $text('host'),
                'program' => null,
                'database' => $text('db'),
                'command' => $text('command'),
                'state' => $text('state'),
                'seconds' => isset($values['time']) && is_numeric($values['time']) ? (int) $values['time'] : null,
                'statement' => $this->shortStatement($values['info'] ?? null),
                'own' => $id === $own,
            ];
        }, $connection->select('show full processlist')));
    }

    public function endProcessStatement(int $id, bool $wholeSession): PlannedStatement
    {
        return new PlannedStatement(($wholeSession ? 'KILL ' : 'KILL QUERY ').$id);
    }

    public function endProcess(Connection $connection, int $id, bool $wholeSession): bool
    {
        try {
            $connection->statement($this->endProcessStatement($id, $wholeSession)->sql);

            return true;
        } catch (QueryException $exception) {
            // 1094: Unknown thread id — der Prozess ist schon weg.
            if ($this->errorCodes($exception)[1] === 1094) {
                return false;
            }

            throw $exception;
        }
    }

    public function variables(Connection $connection): array
    {
        return $this->settingRows($connection->select('show global variables'), 'Variable_name', 'Value');
    }

    public function statusValues(Connection $connection): array
    {
        return $this->settingRows($connection->select('show global status'), 'Variable_name', 'Value');
    }

    public function accountsHaveHosts(): bool
    {
        return true;
    }

    public function accounts(Connection $connection): array
    {
        $current = $this->currentAccount($connection);
        $accounts = [];

        foreach ($connection->select($this->accountQuery()) as $row) {
            $values = array_change_key_case((array) $row);
            $account = new AccountName((string) $values['user'], (string) $values['host']);

            $accounts[] = [
                'name' => $account->name,
                'host' => $account->host,
                'attributes' => $this->accountAttributes($values),
                'grants' => $this->grantsOf($connection, $account, $values),
                'own' => $account->equals($current),
            ];
        }

        return $accounts;
    }

    public function currentAccount(Connection $connection): AccountName
    {
        $account = (string) $connection->selectOne('select current_user() as account')?->account;
        $at = (int) strrpos($account, '@');

        return new AccountName(substr($account, 0, $at), substr($account, $at + 1));
    }

    public function privilegeChoices(): array
    {
        $database = [
            'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'CREATE VIEW', 'SHOW VIEW',
            'CREATE ROUTINE', 'ALTER ROUTINE', 'EXECUTE', 'EVENT', 'TRIGGER', 'LOCK TABLES', 'CREATE TEMPORARY TABLES',
            'REFERENCES',
        ];

        return [
            PrivilegeLevel::Server->value => [...$database, 'PROCESS', 'RELOAD', 'SHOW DATABASES', 'CREATE USER', 'REPLICATION CLIENT', 'ALL PRIVILEGES'],
            PrivilegeLevel::Database->value => [...$database, 'ALL PRIVILEGES'],
            PrivilegeLevel::Table->value => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'CREATE VIEW', 'SHOW VIEW', 'TRIGGER', 'REFERENCES', 'ALL PRIVILEGES'],
        ];
    }

    public function createAccountStatements(Connection $connection, AccountName $account, string $password): array
    {
        $sql = 'CREATE USER '.$this->accountLiteral($connection, $account).' IDENTIFIED BY ';

        return [new PlannedStatement($sql.$this->quoteString($connection, $password), $sql.PlannedStatement::MASK)];
    }

    public function dropAccountStatements(Connection $connection, AccountName $account): array
    {
        return [new PlannedStatement('DROP USER '.$this->accountLiteral($connection, $account))];
    }

    public function privilegeStatements(Connection $connection, bool $grant, AccountName $account, PrivilegeTarget $target, array $privileges): array
    {
        $on = match ($target->level) {
            PrivilegeLevel::Server => '*.*',
            // Auf Datenbank-Ebene sind _ und % Platzhalter: «my_db» träfe sonst auch «myXdb».
            PrivilegeLevel::Database => $this->quoteIdentifier(addcslashes((string) $target->database, '_%')).'.*',
            PrivilegeLevel::Table => $this->quoteIdentifier((string) $target->database).'.'.$this->quoteIdentifier((string) $target->table),
            PrivilegeLevel::Schema => throw new InvalidArgumentException('MySQL und MariaDB kennen keine Schemas neben den Datenbanken.'),
        };

        return [new PlannedStatement(
            ($grant ? 'GRANT ' : 'REVOKE ').implode(', ', $this->allowedPrivileges($target->level, $privileges)).' ON '.$on
            .($grant ? ' TO ' : ' FROM ').$this->accountLiteral($connection, $account),
        )];
    }

    public function maintenanceOperations(): array
    {
        return [MaintenanceOperation::Analyze, MaintenanceOperation::Optimize, MaintenanceOperation::Check];
    }

    public function maintenanceForTables(MaintenanceOperation $operation): bool
    {
        return true;
    }

    public function maintenanceStatements(Connection $connection, MaintenanceOperation $operation, string $schema, ?array $tables): array
    {
        $keyword = match ($operation) {
            MaintenanceOperation::Analyze => 'ANALYZE TABLE ',
            MaintenanceOperation::Optimize => 'OPTIMIZE TABLE ',
            MaintenanceOperation::Check => 'CHECK TABLE ',
            MaintenanceOperation::Vacuum => throw new InvalidArgumentException('VACUUM gibt es nur bei PostgreSQL und SQLite.'),
        };
        $tables ??= $this->baseTables($connection, $schema);

        if ($tables === []) {
            return [];
        }

        $names = array_map(fn (string $table): string => $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table), $tables);

        return [new PlannedStatement($keyword.implode(', ', $names), objects: $tables)];
    }

    public function runMaintenance(Connection $connection, PlannedStatement $statement): array
    {
        return array_values(array_map(function (object $row): array {
            $values = array_change_key_case((array) $row);
            $type = strtolower((string) ($values['msg_type'] ?? ''));
            $message = (string) ($values['msg_text'] ?? '');

            return [
                'table' => isset($values['table']) ? (string) $values['table'] : null,
                'status' => match ($type) {
                    'status' => in_array(strtolower($message), ['ok', 'table is already up to date'], true) ? 'ok' : 'error',
                    'info', 'note' => 'note',
                    'warning' => 'warning',
                    default => 'error',
                },
                'message' => $message,
            ];
        }, $connection->select($statement->sql)));
    }

    /** Wie die Grammatik von MySQL: Backticks, verdoppelt. */
    protected function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /** Ohne Passwort-Hash: MariaDB hängt ihn an SHOW GRANTS an — nie lesen. */
    protected function accountQuery(): string
    {
        return 'select user, host, account_locked, password_expired from mysql.user order by user, host';
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    protected function accountAttributes(array $values): array
    {
        return array_values(array_filter([
            ($values['account_locked'] ?? 'N') === 'Y' ? 'locked' : null,
            ($values['password_expired'] ?? 'N') === 'Y' ? 'expired' : null,
        ]));
    }

    /**
     * SHOW GRANTS ohne «IDENTIFIED BY PASSWORD '…'» (MariaDB) — der Hash ist ein Geheimnis.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    protected function grantsOf(Connection $connection, AccountName $account, array $values): array
    {
        return array_values(array_map(
            fn (object $row): string => (string) preg_replace('/\s+IDENTIFIED\s+(BY|VIA|WITH)\b.*$/is', '', (string) (array_values((array) $row)[0] ?? '')),
            $connection->select('show grants for '.$this->accountLiteral($connection, $account)),
        ));
    }

    /** 'name'@'host' — beides Zeichenketten, gequotet über PDO. */
    protected function accountLiteral(Connection $connection, AccountName $account): string
    {
        return $this->quoteString($connection, $account->name).'@'.$this->quoteString($connection, (string) $account->host);
    }

    /**
     * Ergebnis von SHOW … VARIABLES/STATUS als Name (klein) → Wert.
     *
     * @param  array<int, object>  $rows
     * @return array<string, string>
     */
    private function keyValues(array $rows): array
    {
        $values = [];

        foreach ($rows as $row) {
            $columns = array_values((array) $row);
            $values[strtolower((string) ($columns[0] ?? ''))] = (string) ($columns[1] ?? '');
        }

        return $values;
    }

    /**
     * `ANALYZE TABLE` meldet Fehler als Ergebniszeile statt als Ausnahme — deshalb lesen und prüfen. MySQL 8 aktualisiert
     * dabei auch die zwischengespeicherte Zeilenzahl in information_schema (sonst bis zu 24 Stunden alt).
     */
    public function analyze(Connection $connection, string $schema, string $table): void
    {
        foreach ($connection->select('analyze table '.$connection->getQueryGrammar()->wrap($schema.'.'.$table)) as $row) {
            $values = array_change_key_case((array) $row);

            if (strtolower((string) ($values['msg_type'] ?? '')) === 'error') {
                throw new RuntimeException((string) ($values['msg_text'] ?? 'ANALYZE TABLE'));
            }
        }
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
