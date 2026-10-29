<?php

namespace App\Dialects\Objects;

use App\Enums\ObjectKind;
use Illuminate\Database\Connection;

/**
 * Objekte in SQLite: nur Sichten und Trigger — Routinen, Ereignisse, Sequenzen und Typen gibt es nicht. Die Definition
 * steht so, wie die Datei sie speichert, in `sqlite_schema`. Ersetzen kennt SQLite nicht: Ändern heisst löschen und
 * neu anlegen, in einer Transaktion. Ohne Sitzungs-Schema: In einer angehängten Datenbank muss der Name das Schema
 * tragen («aux».name).
 */
class SqliteObjects extends BaseObjects
{
    public function kinds(): array
    {
        return [ObjectKind::View, ObjectKind::Trigger];
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public function replacesInPlace(ObjectKind $kind): bool
    {
        return false;
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    public function contextStatement(string $schema): ?string
    {
        return null;
    }

    public function dropStatement(ObjectDefinition $object): string
    {
        return ($object->kind === ObjectKind::View ? 'DROP VIEW ' : 'DROP TRIGGER ').$this->qualified($object->schema, $object->name);
    }

    public function describe(Connection $connection, string $schema, ObjectKind $kind, string $name, ?string $qualifier = null): ?ObjectDefinition
    {
        if (! in_array($kind, $this->kinds(), true)) {
            return null;
        }

        $info = $this->row($connection->selectOne(
            'select name, tbl_name as tbl, sql from '.$this->quote($schema).'.sqlite_schema where type = ? and name = ?',
            [$kind->value, $name],
        ));

        if ($info === [] || $info['sql'] === null) {
            return null;
        }

        $ddl = rtrim((string) $info['sql']).';';

        if ($kind === ObjectKind::View) {
            return new ObjectDefinition($kind, $schema, (string) $info['name'], null, $ddl, $ddl, columns: array_map(
                fn (array $row): array => ['name' => (string) $row['name'], 'type' => (string) $row['type']],
                $this->rows($connection->select('select name, type from pragma_table_info(?, ?) order by cid', [$info['name'], $schema])),
            ));
        }

        preg_match('/\bTRIGGER\s+(?:IF\s+NOT\s+EXISTS\s+)?\S+\s+(BEFORE|AFTER|INSTEAD\s+OF)?\s*(INSERT|DELETE|UPDATE(?:\s+OF\s+[^\s]+)?)\s+ON\s/i', $ddl, $match);

        return new ObjectDefinition($kind, $schema, (string) $info['name'], null, $ddl, $ddl,
            facts: $this->facts([
                'table' => $info['tbl'],
                'timing' => strtoupper(($match[1] ?? '') !== '' ? $match[1] : 'BEFORE'),
                'event' => strtoupper($match[2] ?? ''),
            ]),
            references: [['kind' => 'table', 'schema' => $schema, 'name' => (string) $info['tbl'], 'qualifier' => null]],
        );
    }

    public function template(ObjectKind $kind, string $schema): string
    {
        $prefix = $schema === 'main' ? '' : $this->quote($schema).'.';

        return $kind === ObjectKind::View
            ? 'CREATE VIEW '.$prefix."new_view AS\nSELECT 1 AS value;"
            : 'CREATE TRIGGER '.$prefix."new_trigger\nAFTER INSERT ON table_name\nBEGIN\n    SELECT 1;\nEND;";
    }

    public function diagram(Connection $connection, string $schema): array
    {
        $tables = [];
        $primary = [];

        foreach ($this->rows($connection->select(
            'select m.name as tbl, p.name as name, p.type as type, p."notnull" as notnull, p.pk as pk
             from '.$this->quote($schema).".sqlite_schema m
             join pragma_table_info(m.name, ?) p
             where m.type = 'table' and m.name not like 'sqlite_%'
             order by m.name, p.cid",
            [$schema],
        )) as $row) {
            $tables[(string) $row['tbl']]['name'] = (string) $row['tbl'];
            $tables[(string) $row['tbl']]['columns'][] = [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'primary' => (int) $row['pk'] > 0,
                'nullable' => $row['notnull'] === '0' && (int) $row['pk'] === 0,
            ];

            if ((int) $row['pk'] > 0) {
                $primary[(string) $row['tbl']][(int) $row['pk']] = (string) $row['name'];
            }
        }

        $rows = [];
        foreach ($this->rows($connection->select(
            'select m.name as tbl, f.id as id, f."table" as ref_tbl, f."from" as col, f."to" as ref_col
             from '.$this->quote($schema).".sqlite_schema m
             join pragma_foreign_key_list(m.name, ?) f
             where m.type = 'table' and m.name not like 'sqlite_%'
             order by m.name, f.id, f.seq",
            [$schema],
        )) as $row) {
            // Ohne Spalte zeigt der Fremdschlüssel auf den Primärschlüssel der Elterntabelle.
            $position = count(array_filter($rows, fn (array $seen): bool => $seen['tbl'] === $row['tbl'] && $seen['name'] === $row['tbl'].'_fk_'.$row['id']));
            $parentKey = $primary[(string) $row['ref_tbl']] ?? [];
            ksort($parentKey);
            $parentKey = array_values($parentKey);
            $rows[] = [
                'name' => $row['tbl'].'_fk_'.$row['id'],
                'tbl' => $row['tbl'],
                'col' => $row['col'],
                'ref_tbl' => $row['ref_tbl'],
                'ref_col' => $row['ref_col'] ?? ($parentKey[$position] ?? 'rowid'),
            ];
        }

        return ['tables' => array_values($tables), 'relations' => $this->groupRelations($rows)];
    }
}
