<?php

namespace App\Services\Ai;

use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use Illuminate\Database\Connection;

/**
 * Umriss eines Schemas für die KI-Hilfe (Q-08): Tabellen und Sichten mit Spalten, Typen, NULL, Primär- und
 * Fremdschlüsseln — aus dem Katalog, nie aus den Tabellen selbst. Zwei Abfragen je Treiber, höchstens 20'000 Spalten.
 * Wird der Text zu lang, fallen ganze Tabellen weg; `omitted` sagt, wie viele.
 */
final class SchemaOutline
{
    public function __construct(private readonly TargetConnections $targets) {}

    /**
     * @return array{text: string, tables: int, omitted: int}
     */
    public function describe(DatabaseConnection $connection, string $schema, ?string $database, int $maxChars): array
    {
        $target = $this->targets->open($connection, $database);

        [$columns, $foreignKeys] = $connection->driver->dialect()->runReadOnly(
            $target,
            fn (): array => [$this->columns($target, $connection->driver, $schema), $this->foreignKeys($target, $connection->driver, $schema)],
        );

        $references = [];
        foreach ($foreignKeys as $key) {
            $references[(string) $key->t][(string) $key->c] = $key->rt.'.'.$key->rc;
        }

        $tables = [];
        foreach ($columns as $column) {
            $table = (string) $column->t;
            $tables[$table][] = trim(implode(' ', array_filter([
                (string) $column->c,
                strtolower((string) $column->ty),
                (bool) $column->pk ? 'PK' : null,
                ! (bool) $column->pk && ! (bool) $column->nullable ? 'NOT NULL' : null,
                isset($references[$table][(string) $column->c]) ? '→ '.$references[$table][(string) $column->c] : null,
            ])));
        }

        $lines = [];
        $length = 0;
        $omitted = 0;

        foreach ($tables as $table => $definitions) {
            $line = $table.' ('.implode(', ', $definitions).')';

            if ($length + strlen($line) + 1 > $maxChars) {
                $omitted++;

                continue;
            }

            $lines[] = $line;
            $length += strlen($line) + 1;
        }

        return ['text' => implode("\n", $lines), 'tables' => count($lines), 'omitted' => $omitted];
    }

    /**
     * @return list<object{t: string, c: string, ty: string, nullable: int|bool, pk: int|bool}>
     */
    private function columns(Connection $target, DatabaseDriver $driver, string $schema): array
    {
        /** @var list<object{t: string, c: string, ty: string, nullable: int|bool, pk: int|bool}> */
        return match ($driver) {
            DatabaseDriver::Sqlite => $target->select(
                'select m.name as t, p.name as c, p.type as ty, p."notnull" = 0 as nullable, p.pk > 0 as pk
                 from '.$target->getQueryGrammar()->wrap($schema).'.sqlite_master m
                 join pragma_table_info(m.name, ?) p
                 where m.type in (\'table\', \'view\') and m.name not like \'sqlite_%\'
                 order by m.name, p.cid limit 20000',
                [$schema],
            ),
            DatabaseDriver::Postgres => $target->select(
                'select c.table_name as t, c.column_name as c,
                        case when c.data_type = \'USER-DEFINED\' then c.udt_name else c.data_type end as ty,
                        c.is_nullable = \'YES\' as nullable,
                        exists (
                            select 1 from information_schema.table_constraints tc
                            join information_schema.key_column_usage k
                              on k.constraint_name = tc.constraint_name and k.constraint_schema = tc.constraint_schema
                            where tc.constraint_type = \'PRIMARY KEY\' and tc.table_schema = c.table_schema
                              and tc.table_name = c.table_name and k.column_name = c.column_name
                        ) as pk
                 from information_schema.columns c where c.table_schema = ?
                 order by c.table_name, c.ordinal_position limit 20000',
                [$schema],
            ),
            default => $target->select(
                'select table_name as t, column_name as c, column_type as ty, is_nullable = \'YES\' as nullable,
                        column_key = \'PRI\' as pk
                 from information_schema.columns where table_schema = ?
                 order by table_name, ordinal_position limit 20000',
                [$schema],
            ),
        };
    }

    /**
     * @return list<object{t: string, c: string, rt: string, rc: string}>
     */
    private function foreignKeys(Connection $target, DatabaseDriver $driver, string $schema): array
    {
        /** @var list<object{t: string, c: string, rt: string, rc: string}> */
        return match ($driver) {
            DatabaseDriver::Sqlite => $target->select(
                'select m.name as t, f."from" as c, f."table" as rt, f."to" as rc
                 from '.$target->getQueryGrammar()->wrap($schema).'.sqlite_master m
                 join pragma_foreign_key_list(m.name, ?) f
                 where m.type = \'table\' limit 20000',
                [$schema],
            ),
            DatabaseDriver::Postgres => $target->select(
                'select k.table_name as t, k.column_name as c, u.table_name as rt, u.column_name as rc
                 from information_schema.table_constraints tc
                 join information_schema.key_column_usage k
                   on k.constraint_name = tc.constraint_name and k.constraint_schema = tc.constraint_schema
                 join information_schema.constraint_column_usage u
                   on u.constraint_name = tc.constraint_name and u.constraint_schema = tc.constraint_schema
                 where tc.constraint_type = \'FOREIGN KEY\' and tc.table_schema = ? limit 20000',
                [$schema],
            ),
            default => $target->select(
                'select table_name as t, column_name as c, referenced_table_name as rt, referenced_column_name as rc
                 from information_schema.key_column_usage
                 where table_schema = ? and referenced_table_name is not null limit 20000',
                [$schema],
            ),
        };
    }
}
