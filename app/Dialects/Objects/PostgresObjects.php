<?php

namespace App\Dialects\Objects;

use App\Enums\DatabaseDriver;
use App\Enums\ObjectKind;
use App\Services\Objects\DefinitionHead;
use Illuminate\Database\Connection;

/**
 * Objekte in PostgreSQL (ab 14): Sichten, Funktionen, Prozeduren, Trigger, Sequenzen und eigene Typen (Aufzählung,
 * zusammengesetzt, Domäne, Bereich). Definitionen aus dem Katalog (`pg_get_viewdef`, `pg_get_functiondef`,
 * `pg_get_triggerdef`), Sequenzen und Typen zusammengesetzt. Sichten, Routinen und Trigger lassen sich an Ort und
 * Stelle ersetzen; Sequenzen und Typen ändert `ALTER`. Alles läuft in einer Transaktion.
 *
 * Routinen gibt es mit gleichem Namen mehrfach (Überladung) — unterschieden über die Parameter; Trigger heissen je
 * Tabelle — unterschieden über die Tabelle.
 */
class PostgresObjects extends BaseObjects
{
    /** typtype → Art des Typs */
    private const array VARIANTS = ['e' => 'enum', 'c' => 'composite', 'd' => 'domain', 'r' => 'range'];

    /** Eigene Typen: Aufzählung, Domäne, Bereich und zusammengesetzte Typen, die nicht zu einer Tabelle gehören. */
    private const string OWN_TYPES = "(t.typtype in ('e', 'd', 'r') or (t.typtype = 'c' and c.relkind = 'c'))
        and not exists (select 1 from pg_depend d where d.objid = t.oid and d.deptype = 'e')";

    public function kinds(): array
    {
        return [ObjectKind::View, ObjectKind::Function, ObjectKind::Procedure, ObjectKind::Trigger, ObjectKind::Sequence, ObjectKind::Type];
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public function replacesInPlace(ObjectKind $kind): bool
    {
        return in_array($kind, [ObjectKind::View, ObjectKind::Function, ObjectKind::Procedure, ObjectKind::Trigger], true);
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    public function contextStatement(string $schema): string
    {
        return 'SET LOCAL search_path TO '.$this->quote($schema).($schema !== 'public' ? ', "public"' : '');
    }

    public function types(Connection $connection, string $schema): array
    {
        return array_map(fn (array $row): array => [
            'name' => (string) $row['name'],
            'variant' => self::VARIANTS[(string) $row['typtype']] ?? 'other',
        ], $this->rows($connection->select(
            'select t.typname as name, t.typtype as typtype from pg_type t
             join pg_namespace n on n.oid = t.typnamespace
             left join pg_class c on c.oid = t.typrelid
             where n.nspname = ? and '.self::OWN_TYPES.' order by t.typname',
            [$schema],
        )));
    }

    public function dropStatement(ObjectDefinition $object): string
    {
        $qualified = $this->qualified($object->schema, $object->name);

        return match ($object->kind) {
            ObjectKind::View => 'DROP VIEW '.$qualified,
            ObjectKind::Function => 'DROP FUNCTION '.$qualified.'('.$object->qualifier.')',
            ObjectKind::Procedure => 'DROP PROCEDURE '.$qualified.'('.$object->qualifier.')',
            ObjectKind::Trigger => 'DROP TRIGGER '.$this->quote($object->name).' ON '.$this->qualified($object->schema, (string) $object->qualifier),
            ObjectKind::Sequence => 'DROP SEQUENCE '.$qualified,
            default => ($object->variant === 'domain' ? 'DROP DOMAIN ' : 'DROP TYPE ').$qualified,
        };
    }

    public function describe(Connection $connection, string $schema, ObjectKind $kind, string $name, ?string $qualifier = null): ?ObjectDefinition
    {
        return match ($kind) {
            ObjectKind::View => $this->view($connection, $schema, $name),
            ObjectKind::Function, ObjectKind::Procedure => $this->routine($connection, $schema, $kind, $name, $qualifier),
            ObjectKind::Trigger => $this->trigger($connection, $schema, $name, $qualifier),
            ObjectKind::Sequence => $this->sequence($connection, $schema, $name),
            ObjectKind::Type => $this->type($connection, $schema, $name),
            default => null,
        };
    }

    public function template(ObjectKind $kind, string $schema): string
    {
        return match ($kind) {
            ObjectKind::View => "CREATE VIEW new_view AS\nSELECT 1 AS value;",
            ObjectKind::Function => "CREATE FUNCTION new_function(value integer)\nRETURNS integer\nLANGUAGE sql\nIMMUTABLE\nAS \$\$\n    SELECT value * 2\n\$\$;",
            ObjectKind::Procedure => "CREATE PROCEDURE new_procedure(value integer)\nLANGUAGE plpgsql\nAS \$\$\nBEGIN\n    RAISE NOTICE 'Value: %', value;\nEND\n\$\$;",
            ObjectKind::Trigger => "CREATE TRIGGER new_trigger\nBEFORE INSERT ON table_name\nFOR EACH ROW\nEXECUTE FUNCTION trigger_function();",
            ObjectKind::Sequence => "CREATE SEQUENCE new_sequence\nAS bigint\nINCREMENT BY 1\nSTART WITH 1;",
            default => "CREATE TYPE new_type AS ENUM ('first', 'second');",
        };
    }

    public function diagram(Connection $connection, string $schema): array
    {
        $tables = [];

        foreach ($this->rows($connection->select(
            'select c.relname as tbl, a.attname as name, format_type(a.atttypid, a.atttypmod) as type, not a.attnotnull as nullable,
                    exists (select 1 from pg_index i where i.indrelid = c.oid and i.indisprimary and a.attnum = any(i.indkey)) as pk
             from pg_class c
             join pg_namespace n on n.oid = c.relnamespace
             join pg_attribute a on a.attrelid = c.oid
             where n.nspname = ? and c.relkind in (\'r\', \'p\') and not c.relispartition and a.attnum > 0 and not a.attisdropped
             order by c.relname, a.attnum',
            [$schema],
        )) as $row) {
            $tables[(string) $row['tbl']]['name'] = (string) $row['tbl'];
            $tables[(string) $row['tbl']]['columns'][] = [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'primary' => $row['pk'] === '1',
                'nullable' => $row['nullable'] === '1',
            ];
        }

        // Ohne die Kopien auf Partitionen (conparentid): die Beziehung gehört zur partitionierten Tabelle.
        $relations = $this->groupRelations($this->rows($connection->select(
            "select con.conname as name, rel.relname as tbl, a.attname as col, ref.relname as ref_tbl, ra.attname as ref_col
             from pg_constraint con
             join pg_class rel on rel.oid = con.conrelid
             join pg_namespace n on n.oid = rel.relnamespace
             join pg_class ref on ref.oid = con.confrelid
             join pg_namespace rn on rn.oid = ref.relnamespace
             cross join lateral unnest(con.conkey, con.confkey) with ordinality as k(attnum, refnum, ord)
             join pg_attribute a on a.attrelid = con.conrelid and a.attnum = k.attnum
             join pg_attribute ra on ra.attrelid = con.confrelid and ra.attnum = k.refnum
             where con.contype = 'f' and con.conparentid = 0 and n.nspname = ? and rn.nspname = ?
             order by rel.relname, con.conname, k.ord",
            [$schema, $schema],
        )));

        return ['tables' => array_values($tables), 'relations' => $relations];
    }

    private function view(Connection $connection, string $schema, string $name): ?ObjectDefinition
    {
        $info = $this->row($connection->selectOne(
            "select c.oid, c.relname as name, pg_get_userbyid(c.relowner) as owner, obj_description(c.oid, 'pg_class') as comment,
                    array_to_string(c.reloptions, ',') as options, pg_get_viewdef(c.oid, true) as definition
             from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = ? and c.relname = ? and c.relkind = 'v'",
            [$schema, $name],
        ));

        if ($info === []) {
            return null;
        }

        $check = null;
        $options = [];
        foreach (array_filter(explode(',', (string) $info['options'])) as $option) {
            [$key, $value] = array_pad(explode('=', $option, 2), 2, 'true');
            $key === 'check_option' ? $check = strtoupper($value) : $options[] = $key.'='.$value;
        }

        $body = rtrim(rtrim((string) $info['definition']), ';');
        $tail = ($options !== [] ? ' WITH ('.implode(', ', $options).')' : '').' AS'."\n".$body.($check !== null ? "\n  WITH ".$check.' CHECK OPTION' : '').';';
        $qualified = $this->qualified($schema, (string) $info['name']);

        return new ObjectDefinition(
            ObjectKind::View,
            $schema,
            (string) $info['name'],
            null,
            'CREATE VIEW '.$qualified.$tail,
            'CREATE OR REPLACE VIEW '.$qualified.$tail,
            facts: $this->facts(['check_option' => $check, 'owner' => $info['owner'], 'comment' => $info['comment']]),
            columns: $this->columnsOf($connection, (string) $info['oid']),
        );
    }

    private function routine(Connection $connection, string $schema, ObjectKind $kind, string $name, ?string $qualifier): ?ObjectDefinition
    {
        $rows = $this->rows($connection->select(
            "select p.oid, p.proname as name, pg_get_function_identity_arguments(p.oid) as args, pg_get_function_result(p.oid) as result,
                    l.lanname as language, p.provolatile as volatility, p.prosecdef as secdef, pg_get_userbyid(p.proowner) as owner,
                    obj_description(p.oid, 'pg_proc') as comment
             from pg_proc p
             join pg_namespace n on n.oid = p.pronamespace
             join pg_language l on l.oid = p.prolang
             where n.nspname = ? and p.proname = ? and p.prokind = ?
               and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e')
             order by p.oid",
            [$schema, $name, $kind === ObjectKind::Procedure ? 'p' : 'f'],
        ));
        $info = $qualifier === null ? ($rows[0] ?? null) : array_find($rows, fn (array $row): bool => $row['args'] === $qualifier);

        if ($info === null) {
            return null;
        }

        $ddl = rtrim((string) $connection->selectOne('select pg_get_functiondef(?::oid) as d', [$info['oid']])?->d).';';

        return new ObjectDefinition(
            $kind,
            $schema,
            (string) $info['name'],
            (string) $info['args'],
            $ddl,
            $ddl,
            facts: $this->facts([
                'arguments' => $info['args'] !== '' ? $info['args'] : '—',
                'returns' => $info['result'],
                'language' => $info['language'],
                'volatility' => $kind === ObjectKind::Function ? ['i' => 'IMMUTABLE', 's' => 'STABLE', 'v' => 'VOLATILE'][(string) $info['volatility']] ?? null : null,
                'security' => $info['secdef'] === '1' ? 'DEFINER' : 'INVOKER',
                'owner' => $info['owner'],
                'comment' => $info['comment'],
            ]),
            overloads: array_map(fn (array $row): string => (string) $row['args'], $rows),
        );
    }

    private function trigger(Connection $connection, string $schema, string $name, ?string $qualifier): ?ObjectDefinition
    {
        $rows = $this->rows($connection->select(
            'select t.tgname as name, c.relname as tbl, t.tgenabled as enabled, (t.tgconstraint <> 0) as is_constraint,
                    pf.proname as fn, pn.nspname as fn_schema, pg_get_function_identity_arguments(t.tgfoid) as fn_args,
                    pg_get_triggerdef(t.oid) as definition
             from pg_trigger t
             join pg_class c on c.oid = t.tgrelid
             join pg_namespace n on n.oid = c.relnamespace
             join pg_proc pf on pf.oid = t.tgfoid
             join pg_namespace pn on pn.oid = pf.pronamespace
             where not t.tgisinternal and n.nspname = ? and t.tgname = ?
             order by c.relname',
            [$schema, $name],
        ));
        $info = $qualifier === null ? ($rows[0] ?? null) : array_find($rows, fn (array $row): bool => $row['tbl'] === $qualifier);

        if ($info === null) {
            return null;
        }

        $ddl = (string) $info['definition'];
        preg_match('/\b(BEFORE|AFTER|INSTEAD OF)\s+(.+?)\s+ON\s/i', $ddl, $match);
        $constraint = $info['is_constraint'] === '1';

        return new ObjectDefinition(
            ObjectKind::Trigger,
            $schema,
            (string) $info['name'],
            (string) $info['tbl'],
            $ddl.';',
            ($constraint ? $ddl : DefinitionHead::withOrReplace($ddl, DatabaseDriver::Postgres)).';',
            facts: $this->facts([
                'table' => $info['tbl'],
                'timing' => strtoupper($match[1] ?? ''),
                'event' => strtoupper($match[2] ?? ''),
                'function' => $info['fn_schema'].'.'.$info['fn'].'('.$info['fn_args'].')',
                'status' => ['O' => 'ENABLED', 'D' => 'DISABLED', 'R' => 'REPLICA', 'A' => 'ALWAYS'][(string) $info['enabled']] ?? null,
            ]),
            references: [
                ['kind' => 'table', 'schema' => $schema, 'name' => (string) $info['tbl'], 'qualifier' => null],
                ['kind' => 'function', 'schema' => (string) $info['fn_schema'], 'name' => (string) $info['fn'], 'qualifier' => (string) $info['fn_args']],
            ],
            overloads: array_map(fn (array $row): string => (string) $row['tbl'], $rows),
            variant: $constraint ? 'constraint' : null,
        );
    }

    private function sequence(Connection $connection, string $schema, string $name): ?ObjectDefinition
    {
        $info = $this->row($connection->selectOne(
            "select s.sequencename as name, s.data_type, s.start_value, s.min_value, s.max_value, s.increment_by, s.cycle, s.cache_size,
                    s.last_value, pg_get_userbyid(c.relowner) as owner, dc.relname as owned_table, a.attname as owned_column
             from pg_sequences s
             join pg_namespace n on n.nspname = s.schemaname
             join pg_class c on c.relnamespace = n.oid and c.relname = s.sequencename
             left join pg_depend d on d.objid = c.oid and d.classid = 'pg_class'::regclass and d.refclassid = 'pg_class'::regclass and d.deptype in ('a', 'i')
             left join pg_class dc on dc.oid = d.refobjid
             left join pg_attribute a on a.attrelid = d.refobjid and a.attnum = d.refobjsubid
             where s.schemaname = ? and s.sequencename = ?",
            [$schema, $name],
        ));

        if ($info === []) {
            return null;
        }

        $qualified = $this->qualified($schema, (string) $info['name']);
        $options = "\n    INCREMENT BY ".$info['increment_by']
            ."\n    MINVALUE ".$info['min_value']
            ."\n    MAXVALUE ".$info['max_value']
            ."\n    START WITH ".$info['start_value']
            ."\n    CACHE ".$info['cache_size']
            ."\n    ".($info['cycle'] === '1' ? 'CYCLE' : 'NO CYCLE');
        $owned = $info['owned_table'] !== null ? $this->qualified($schema, (string) $info['owned_table']).'.'.$this->quote((string) $info['owned_column']) : null;
        $ddl = 'CREATE SEQUENCE '.$qualified."\n    AS ".$info['data_type'].$options.';'
            .($owned !== null ? "\n\nALTER SEQUENCE ".$qualified.' OWNED BY '.$owned.';' : '');

        return new ObjectDefinition(
            ObjectKind::Sequence,
            $schema,
            (string) $info['name'],
            null,
            $ddl,
            'ALTER SEQUENCE '.$qualified.$options.';',
            facts: $this->facts([
                'current' => $info['last_value'] ?? '—',
                'data_type' => $info['data_type'],
                'increment' => $info['increment_by'],
                'minimum' => $info['min_value'],
                'maximum' => $info['max_value'],
                'start' => $info['start_value'],
                'cache' => $info['cache_size'],
                'cycle' => $info['cycle'] === '1' ? 'CYCLE' : 'NO CYCLE',
                'owned_by' => $info['owned_table'] !== null ? $info['owned_table'].'.'.$info['owned_column'] : null,
                'owner' => $info['owner'],
            ]),
            references: $info['owned_table'] !== null ? [['kind' => 'table', 'schema' => $schema, 'name' => (string) $info['owned_table'], 'qualifier' => null]] : [],
        );
    }

    private function type(Connection $connection, string $schema, string $name): ?ObjectDefinition
    {
        $info = $this->row($connection->selectOne(
            "select t.oid, t.typname as name, t.typtype as typtype, t.typrelid as relid, format_type(t.typbasetype, t.typtypmod) as base,
                    t.typnotnull as notnull, t.typdefault as def, pg_get_userbyid(t.typowner) as owner, obj_description(t.oid, 'pg_type') as comment
             from pg_type t
             join pg_namespace n on n.oid = t.typnamespace
             left join pg_class c on c.oid = t.typrelid
             where n.nspname = ? and t.typname = ? and ".self::OWN_TYPES,
            [$schema, $name],
        ));

        if ($info === []) {
            return null;
        }

        $variant = self::VARIANTS[(string) $info['typtype']] ?? 'other';
        $qualified = $this->qualified($schema, (string) $info['name']);
        $values = $variant === 'enum' ? array_map(fn (array $row): string => (string) $row['label'], $this->rows($connection->select(
            'select enumlabel as label from pg_enum where enumtypid = ?::oid order by enumsortorder',
            [$info['oid']],
        ))) : [];
        $columns = $variant === 'composite' ? $this->columnsOf($connection, (string) $info['relid']) : [];

        [$ddl, $editable] = match ($variant) {
            'enum' => [
                'CREATE TYPE '.$qualified.' AS ENUM ('.implode(', ', array_map($this->literal(...), $values)).');',
                'ALTER TYPE '.$qualified." ADD VALUE 'new_value';",
            ],
            'composite' => [
                'CREATE TYPE '.$qualified." AS (\n".implode(",\n", array_map(fn (array $column): string => '    '.$this->quote($column['name']).' '.$column['type'], $columns))."\n);",
                'ALTER TYPE '.$qualified.' ADD ATTRIBUTE new_attribute text;',
            ],
            'domain' => [
                $this->domainDefinition($connection, $qualified, $info),
                'ALTER DOMAIN '.$qualified.' ADD CONSTRAINT new_check CHECK (VALUE IS NOT NULL);',
            ],
            default => [
                'CREATE TYPE '.$qualified.' AS RANGE (SUBTYPE = '.$connection->selectOne('select format_type(rngsubtype, null) as subtype from pg_range where rngtypid = ?::oid', [$info['oid']])?->subtype.');',
                'ALTER TYPE '.$qualified.' OWNER TO CURRENT_USER;',
            ],
        };

        return new ObjectDefinition(
            ObjectKind::Type,
            $schema,
            (string) $info['name'],
            null,
            $ddl,
            $editable,
            facts: $this->facts([
                'variant' => $variant,
                'base' => $variant === 'domain' ? $info['base'] : null,
                'owner' => $info['owner'],
                'comment' => $info['comment'],
            ]),
            columns: $columns,
            values: $values,
            variant: $variant,
        );
    }

    /**
     * Domäne mit Vorgabe, NOT NULL und ihren Checks.
     *
     * @param  array<string, string|null>  $info
     */
    private function domainDefinition(Connection $connection, string $qualified, array $info): string
    {
        $checks = array_map(
            fn (array $row): string => "\n    CONSTRAINT ".$this->quote((string) $row['name']).' '.$row['definition'],
            $this->rows($connection->select('select conname as name, pg_get_constraintdef(oid) as definition from pg_constraint where contypid = ?::oid order by conname', [$info['oid']])),
        );

        return 'CREATE DOMAIN '.$qualified.' AS '.$info['base']
            .($info['def'] !== null ? ' DEFAULT '.$info['def'] : '')
            .($info['notnull'] === '1' ? ' NOT NULL' : '')
            .implode('', $checks).';';
    }

    /**
     * Spalten einer Sicht bzw. Felder eines zusammengesetzten Typs.
     *
     * @return list<array{name: string, type: string}>
     */
    private function columnsOf(Connection $connection, string $relation): array
    {
        return array_map(fn (array $row): array => ['name' => (string) $row['name'], 'type' => (string) $row['type']], $this->rows($connection->select(
            'select a.attname as name, format_type(a.atttypid, a.atttypmod) as type from pg_attribute a
             where a.attrelid = ?::oid and a.attnum > 0 and not a.attisdropped order by a.attnum',
            [$relation],
        )));
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
