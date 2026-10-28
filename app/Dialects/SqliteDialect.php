<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Enums\MaintenanceOperation;
use Closure;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Pdo\Sqlite;
use Throwable;

/**
 * SQLite 3.35 und neuer: eine Datei in einem erlaubten Ordner (V-05). Kein Server, keine Zugangsdaten, kein Zeitlimit
 * je Anweisung (F-2) — Abfragen aus dem SQL-Editor laufen später in einem eigenen Arbeitsprozess (Q-09).
 * Schreibschutz: die Datei wird nur lesend geöffnet (OPEN_READONLY, F-7).
 */
class SqliteDialect extends BaseDialect
{
    /** PRAGMAs, die nur lesen (W-03). */
    private const array READABLE_PRAGMAS = [
        'application_id', 'auto_vacuum', 'automatic_index', 'busy_timeout', 'cache_size', 'cache_spill', 'cell_size_check',
        'data_version', 'encoding', 'foreign_keys', 'freelist_count', 'journal_mode', 'journal_size_limit',
        'legacy_alter_table', 'locking_mode', 'max_page_count', 'mmap_size', 'page_count', 'page_size', 'query_only',
        'recursive_triggers', 'reverse_unordered_selects', 'schema_version', 'secure_delete', 'synchronous', 'temp_store',
        'trusted_schema', 'user_version', 'wal_autocheckpoint',
    ];

    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::Sqlite;
    }

    public function connectionConfig(ConnectionSettings $settings, ?string $address, int $connectTimeout, bool $readOnly = false): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $address,
            'prefix' => '',
            'foreign_key_constraints' => ! $readOnly,
            'busy_timeout' => $connectTimeout * 1000,
            'options' => $readOnly ? [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY] : [],
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

    public function checkConstraints(Connection $connection, string $schema, string $table): array
    {
        // SQLite führt keinen Katalog der Constraints: nur der CREATE-Text verrät sie (F-1).
        $sql = (string) $connection->selectOne(
            'select sql from '.$this->wrap($connection, $schema).'.sqlite_schema where type = ? and name = ?',
            ['table', $table],
        )?->sql;

        $constraints = [];
        $offset = 0;

        while (preg_match('/(?:CONSTRAINT\s+("?)(\w+)\1\s+)?CHECK\s*\(/i', $sql, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            $start = $match[0][1] + strlen($match[0][0]);
            $definition = $this->balancedUntilClose($sql, $start);
            $constraints[] = ['name' => $match[2][0] ?? 'check_'.(count($constraints) + 1), 'definition' => $definition];
            $offset = $start + strlen($definition);
        }

        return $constraints;
    }

    public function triggers(Connection $connection, string $schema, string $table): array
    {
        return array_map(function (array $row): array {
            preg_match('/\bTRIGGER\s+(?:IF\s+NOT\s+EXISTS\s+)?\S+\s+(BEFORE|AFTER|INSTEAD\s+OF)?\s*(INSERT|DELETE|UPDATE(?:\s+OF\s+[^\s]+)?)\s+ON\s/i', $row['definition'], $match);

            return [
                'name' => $row['name'],
                'timing' => strtoupper(($match[1] ?? '') !== '' ? $match[1] : 'BEFORE'),
                'event' => strtoupper($match[2] ?? ''),
                'definition' => $row['definition'],
            ];
        }, $this->records($connection->select(
            'select name, sql as definition from '.$this->wrap($connection, $schema).'.sqlite_schema where type = ? and tbl_name = ? order by name',
            ['trigger', $table],
        ), ['name', 'definition']));
    }

    public function referencingForeignKeys(Connection $connection, string $schema, string $table): array
    {
        $groups = [];

        foreach ($this->records($connection->select(
            'select m.name as tbl, p.id as id, p."from" as col, p."to" as refcol, p.on_delete as on_delete
             from '.$this->wrap($connection, $schema).'.sqlite_schema m
             join pragma_foreign_key_list(m.name, ?) p
             where m.type = ? and p."table" = ?
             order by m.name, p.id, p.seq',
            [$schema, 'table', $table],
        ), ['tbl', 'id', 'col', 'refcol', 'on_delete']) as $row) {
            $key = $row['tbl'].'#'.$row['id'];
            $groups[$key] ??= ['name' => $row['tbl'].'_fk_'.$row['id'], 'table' => $row['tbl'], 'columns' => [], 'foreignColumns' => [], 'onDelete' => strtolower($row['on_delete'])];
            $groups[$key]['columns'][] = $row['col'];
            $groups[$key]['foreignColumns'][] = $row['refcol'];
        }

        return array_values($groups);
    }

    public function ddl(Connection $connection, string $schema, string $object, bool $isView): string
    {
        // Tabelle bzw. Sicht, dann ihre Indizes und Trigger — so, wie die Datei sie speichert.
        return implode(";\n\n", $this->strings($connection->select(
            'select sql from '.$this->wrap($connection, $schema).".sqlite_schema
             where sql is not null and (name = ? or (tbl_name = ? and type in ('index', 'trigger')))
             order by case type when 'table' then 0 when 'view' then 0 when 'index' then 1 else 2 end, name",
            [$object, $object],
        ), 'sql')).';';
    }

    public function routines(Connection $connection, string $schema): ?array
    {
        return null;
    }

    public function schemaTriggers(Connection $connection, string $schema): array
    {
        return array_map(fn (array $row): array => ['name' => $row['name'], 'table' => $row['tbl']], $this->records($connection->select(
            'select name, tbl_name as tbl from '.$this->wrap($connection, $schema).'.sqlite_schema where type = ? order by name',
            ['trigger'],
        ), ['name', 'tbl']));
    }

    public function events(Connection $connection, string $schema): ?array
    {
        return null;
    }

    public function sequences(Connection $connection, string $schema): ?array
    {
        return null;
    }

    public function supportsStatementTimeout(): bool
    {
        return false;
    }

    public function setStatementTimeout(Connection $connection, int $milliseconds): void
    {
        // Kein Zeitlimit je Anweisung in PDO-SQLite (F-2); Grenze über den Arbeitsprozess (Q-09).
    }

    public function isTimeout(Throwable $error): bool
    {
        return false;
    }

    public function runReadOnly(Connection $connection, Closure $statement): mixed
    {
        // Die Datei ist schon beim Öffnen schreibgeschützt (OPEN_READONLY); PRAGMA query_only ändert daran nichts.
        return $statement();
    }

    public function isReadOnlyViolation(Throwable $error): bool
    {
        $message = $error->getMessage();

        return str_contains($message, 'attempt to write a readonly database') || str_contains($message, 'not authorized');
    }

    public function accountWarnings(Connection $connection): array
    {
        return [];
    }

    /** Kein Server: Datei, Seiten, Journal und die Version der Bibliothek (W-01). */
    public function serverOverview(Connection $connection): array
    {
        $files = $this->records($connection->select("select name, file from pragma_database_list where name <> 'temp' order by seq"), ['name', 'file']);
        $main = $files[0]['file'] ?? '';
        $encoding = $this->pragma($connection, 'encoding');
        $pageSize = (int) $this->pragma($connection, 'page_size');

        $databases = array_map(fn (array $file): array => [
            'name' => $file['name'],
            'size' => (int) $this->pragma($connection, 'page_count', $file['name']) * (int) $this->pragma($connection, 'page_size', $file['name']),
            'charset' => $encoding,
            'collation' => 'BINARY',
        ], $files);

        $autoVacuum = ['0' => 'NONE', '1' => 'FULL', '2' => 'INCREMENTAL'][(string) $this->pragma($connection, 'auto_vacuum')] ?? null;

        return [
            'version' => $this->serverVersion($connection),
            'uptime' => null,
            'charset' => $encoding,
            'collation' => 'BINARY',
            'facts' => array_values(array_filter([
                ['key' => 'file', 'value' => $main, 'kind' => 'text'],
                is_file($main) ? ['key' => 'file_size', 'value' => (int) filesize($main), 'kind' => 'bytes'] : null,
                ['key' => 'page_size', 'value' => $pageSize, 'kind' => 'bytes'],
                ['key' => 'page_count', 'value' => (int) $this->pragma($connection, 'page_count'), 'kind' => 'number'],
                ['key' => 'freelist_count', 'value' => (int) $this->pragma($connection, 'freelist_count'), 'kind' => 'number'],
                ['key' => 'journal_mode', 'value' => strtoupper((string) $this->pragma($connection, 'journal_mode')), 'kind' => 'text'],
                $autoVacuum !== null ? ['key' => 'auto_vacuum', 'value' => $autoVacuum, 'kind' => 'text'] : null,
            ])),
            'databases' => $databases,
            'schemas' => null,
        ];
    }

    public function sessionId(Connection $connection): ?int
    {
        return null;
    }

    public function processes(Connection $connection): ?array
    {
        return null;
    }

    public function endProcessStatement(int $id, bool $wholeSession): ?PlannedStatement
    {
        return null;
    }

    public function endProcess(Connection $connection, int $id, bool $wholeSession): bool
    {
        return false;
    }

    /** PRAGMAs, die sich lesen lassen, ohne etwas zu verändern (kein wal_checkpoint, kein optimize). */
    public function variables(Connection $connection): array
    {
        $settings = [];

        foreach (self::READABLE_PRAGMAS as $name) {
            try {
                $value = $this->pragma($connection, $name);
            } catch (Throwable) {
                continue;
            }

            if ($value !== null) {
                $settings[] = ['name' => $name, 'value' => $value, 'description' => null];
            }
        }

        return $settings;
    }

    /** Wie die Bibliothek gebaut ist (compile_options) — einen laufenden Zustand gibt es ohne Server nicht. */
    public function statusValues(Connection $connection): array
    {
        return array_map(function (string $option): array {
            [$name, $value] = array_pad(explode('=', $option, 2), 2, 'ON');

            return ['name' => $name, 'value' => $value, 'description' => null];
        }, $this->strings($connection->select('pragma compile_options'), 'compile_options'));
    }

    public function accountsHaveHosts(): bool
    {
        return false;
    }

    public function accounts(Connection $connection): ?array
    {
        return null;
    }

    public function currentAccount(Connection $connection): ?AccountName
    {
        return null;
    }

    public function privilegeChoices(): array
    {
        return [];
    }

    public function createAccountStatements(Connection $connection, AccountName $account, string $password): array
    {
        return [];
    }

    public function dropAccountStatements(Connection $connection, AccountName $account): array
    {
        return [];
    }

    public function privilegeStatements(Connection $connection, bool $grant, AccountName $account, PrivilegeTarget $target, array $privileges): array
    {
        return [];
    }

    public function maintenanceOperations(): array
    {
        return [MaintenanceOperation::Analyze, MaintenanceOperation::Vacuum, MaintenanceOperation::Check];
    }

    /** VACUUM baut immer die ganze Datei neu auf. */
    public function maintenanceForTables(MaintenanceOperation $operation): bool
    {
        return $operation !== MaintenanceOperation::Vacuum;
    }

    public function maintenanceStatements(Connection $connection, MaintenanceOperation $operation, string $schema, ?array $tables): array
    {
        $prefix = $this->quoteIdentifier($schema);

        return match ($operation) {
            MaintenanceOperation::Analyze => $tables === null
                ? [new PlannedStatement('ANALYZE '.$prefix)]
                : array_map(fn (string $table): PlannedStatement => new PlannedStatement('ANALYZE '.$prefix.'.'.$this->quoteIdentifier($table), objects: [$table]), $tables),
            MaintenanceOperation::Vacuum => $tables === null
                ? [new PlannedStatement('VACUUM '.$prefix)]
                : throw new InvalidArgumentException('VACUUM gilt bei SQLite immer der ganzen Datei.'),
            MaintenanceOperation::Check => $tables === null
                ? [new PlannedStatement('PRAGMA '.$prefix.'.integrity_check')]
                : array_map(fn (string $table): PlannedStatement => new PlannedStatement('PRAGMA '.$prefix.'.integrity_check('.$this->quoteIdentifier($table).')', objects: [$table]), $tables),
            MaintenanceOperation::Optimize => throw new InvalidArgumentException('OPTIMIZE gibt es nur bei MySQL und MariaDB.'),
        };
    }

    /** integrity_check meldet «ok» oder je Fund eine Zeile; ANALYZE und VACUUM melden nichts. */
    public function runMaintenance(Connection $connection, PlannedStatement $statement): array
    {
        $table = $statement->objects[0] ?? null;

        if (! str_starts_with($statement->sql, 'PRAGMA ')) {
            $connection->statement($statement->sql);

            return [['table' => $table, 'status' => 'ok', 'message' => 'OK']];
        }

        return array_map(fn (string $line): array => [
            'table' => $table,
            'status' => $line === 'ok' ? 'ok' : 'error',
            'message' => $line,
        ], $this->strings($connection->select($statement->sql), 'integrity_check'));
    }

    /** Wert eines PRAGMAs (erste Spalte der ersten Zeile); null, wenn es keinen gibt. */
    private function pragma(Connection $connection, string $name, string $schema = 'main'): ?string
    {
        $row = $connection->selectOne('pragma '.($schema === 'main' ? '' : $this->quoteIdentifier($schema).'.').$name);

        if ($row === null) {
            return null;
        }

        $value = array_values((array) $row)[0] ?? null;

        return $value === null ? null : (string) $value;
    }

    protected function rowEstimates(Connection $connection, string $schema): array
    {
        // SQLite führt keine Schätzung; sqlite_stat1 gibt es erst nach ANALYZE und nur für indizierte Tabellen.
        return [];
    }

    private function wrap(Connection $connection, string $schema): string
    {
        return $connection->getQueryGrammar()->wrap($schema);
    }

    /**
     * Text bis zur schliessenden Klammer, die zur geöffneten passt.
     */
    private function balancedUntilClose(string $sql, int $start): string
    {
        $depth = 1;
        $length = strlen($sql);

        for ($index = $start; $index < $length; $index++) {
            $depth += match ($sql[$index]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return trim(substr($sql, $start, $index - $start));
            }
        }

        return trim(substr($sql, $start));
    }
}
