<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionProblemKind;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Closure;
use Illuminate\Database\Connection;
use Pdo\Sqlite;
use Throwable;

/**
 * SQLite 3.35 und neuer: eine Datei in einem erlaubten Ordner (V-05). Kein Server, keine Zugangsdaten, kein Zeitlimit
 * je Anweisung (F-2) — Abfragen aus dem SQL-Editor laufen später in einem eigenen Arbeitsprozess (Q-09).
 * Schreibschutz: die Datei wird nur lesend geöffnet (OPEN_READONLY, F-7).
 */
class SqliteDialect extends BaseDialect
{
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
