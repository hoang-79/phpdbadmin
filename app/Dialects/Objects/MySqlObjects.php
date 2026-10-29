<?php

namespace App\Dialects\Objects;

use App\Enums\DatabaseDriver;
use App\Enums\ObjectKind;
use App\Services\Objects\DefinitionHead;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Objekte in MySQL (ab 8.0): Sichten, Funktionen, Prozeduren, Trigger und Ereignisse. Die Definition kommt vom Server
 * (`SHOW CREATE …`); die Vorlage zum Ändern lässt DEFINER weg — sonst bräuchte das Ändern das Recht, im Namen eines
 * anderen Kontos anzulegen. MySQL ersetzt nur Sichten an Ort und Stelle; alles andere wird gelöscht und neu angelegt,
 * ohne Transaktion (DDL schliesst sie in MySQL selbst ab).
 */
class MySqlObjects extends BaseObjects
{
    public function kinds(): array
    {
        return [ObjectKind::View, ObjectKind::Function, ObjectKind::Procedure, ObjectKind::Trigger, ObjectKind::Event];
    }

    public function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    public function replacesInPlace(ObjectKind $kind): bool
    {
        return $kind === ObjectKind::View;
    }

    public function transactionalDdl(): bool
    {
        return false;
    }

    public function contextStatement(string $schema): string
    {
        return 'USE '.$this->quote($schema);
    }

    public function dropStatement(ObjectDefinition $object): string
    {
        return match ($object->kind) {
            ObjectKind::View => 'DROP VIEW ',
            ObjectKind::Function => 'DROP FUNCTION ',
            ObjectKind::Procedure => 'DROP PROCEDURE ',
            ObjectKind::Trigger => 'DROP TRIGGER ',
            default => 'DROP EVENT ',
        }.$this->qualified($object->schema, $object->name);
    }

    public function describe(Connection $connection, string $schema, ObjectKind $kind, string $name, ?string $qualifier = null): ?ObjectDefinition
    {
        return match ($kind) {
            ObjectKind::View => $this->view($connection, $schema, $name),
            ObjectKind::Function, ObjectKind::Procedure => $this->routine($connection, $schema, $kind, $name),
            ObjectKind::Trigger => $this->trigger($connection, $schema, $name),
            ObjectKind::Event => $this->event($connection, $schema, $name),
            default => null,
        };
    }

    public function template(ObjectKind $kind, string $schema): string
    {
        return match ($kind) {
            ObjectKind::View => "CREATE VIEW `new_view` AS\nSELECT 1 AS value;",
            ObjectKind::Function => "CREATE FUNCTION `new_function`(value INT)\nRETURNS INT\nDETERMINISTIC NO SQL\nRETURN value * 2;",
            ObjectKind::Procedure => "CREATE PROCEDURE `new_procedure`(IN value INT)\nBEGIN\n    SELECT value;\nEND;",
            ObjectKind::Trigger => "CREATE TRIGGER `new_trigger`\nBEFORE INSERT ON `table_name`\nFOR EACH ROW\nSET NEW.`column_name` = NEW.`column_name`;",
            default => "CREATE EVENT `new_event`\nON SCHEDULE EVERY 1 DAY\nDO\n    DELETE FROM `table_name` WHERE 1 = 0;",
        };
    }

    public function diagram(Connection $connection, string $schema): array
    {
        $tables = [];

        foreach ($this->rows($connection->select(
            "select c.table_name as tbl, c.column_name as name, c.column_type as type, c.is_nullable as nullable, c.column_key as ckey
             from information_schema.columns c
             join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
             where c.table_schema = ? and t.table_type in ('BASE TABLE', 'SYSTEM VERSIONED')
             order by c.table_name, c.ordinal_position",
            [$schema],
        )) as $row) {
            $tables[(string) $row['tbl']]['name'] = (string) $row['tbl'];
            $tables[(string) $row['tbl']]['columns'][] = [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'primary' => $row['ckey'] === 'PRI',
                'nullable' => $row['nullable'] === 'YES',
            ];
        }

        // Fester Schema-Filter auf beiden Seiten: MariaDB öffnet sonst jede Tabelle jeder Datenbank (TB 01).
        $relations = $this->groupRelations($this->rows($connection->select(
            'select constraint_name as name, table_name as tbl, column_name as col, referenced_table_name as ref_tbl, referenced_column_name as ref_col
             from information_schema.key_column_usage
             where table_schema = ? and referenced_table_schema = ? and referenced_table_name is not null
             order by table_name, constraint_name, ordinal_position',
            [$schema, $schema],
        )));

        return ['tables' => array_values($tables), 'relations' => $relations];
    }

    private function view(Connection $connection, string $schema, string $name): ?ObjectDefinition
    {
        $info = $this->row($connection->selectOne(
            'select table_name as name, check_option, is_updatable, security_type, definer from information_schema.views where table_schema = ? and table_name = ?',
            [$schema, $name],
        ));

        if ($info === []) {
            return null;
        }

        $name = (string) $info['name'];
        $ddl = $this->showCreate($connection, 'VIEW', $schema, $name, 'create view');
        $columns = array_map(fn (array $row): array => ['name' => (string) $row['name'], 'type' => (string) $row['type']], $this->rows($connection->select(
            'select column_name as name, column_type as type from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
            [$schema, $name],
        )));

        return new ObjectDefinition(
            ObjectKind::View,
            $schema,
            $name,
            null,
            $this->statement($ddl),
            $ddl === null ? null : $this->statement(DefinitionHead::withOrReplace(DefinitionHead::withoutDefiner($ddl, $this->driver()), $this->driver())),
            facts: $this->facts([
                'updatable' => $info['is_updatable'],
                'check_option' => $info['check_option'] !== 'NONE' ? $info['check_option'] : null,
                'security' => $info['security_type'],
                'definer' => $info['definer'],
            ]),
            columns: $columns,
        );
    }

    private function routine(Connection $connection, string $schema, ObjectKind $kind, string $name): ?ObjectDefinition
    {
        $info = $this->row($connection->selectOne(
            'select routine_name as name, dtd_identifier as returns, is_deterministic, sql_data_access, security_type, routine_comment, definer, routine_definition as body
             from information_schema.routines where routine_schema = ? and routine_name = ? and routine_type = ?',
            [$schema, $name, strtoupper($kind->value)],
        ));

        if ($info === []) {
            return null;
        }

        $name = (string) $info['name'];
        $arguments = implode(', ', array_map(
            fn (array $row): string => trim(($kind === ObjectKind::Procedure ? $row['mode'].' ' : '').$row['name'].' '.$row['type']),
            $this->rows($connection->select(
                'select parameter_mode as mode, parameter_name as name, dtd_identifier as type from information_schema.parameters
                 where specific_schema = ? and specific_name = ? and routine_type = ? and ordinal_position > 0 order by ordinal_position',
                [$schema, $name, strtoupper($kind->value)],
            )),
        ));
        $ddl = $this->showCreate($connection, strtoupper($kind->value), $schema, $name, 'create '.$kind->value);

        return $this->replaceable($kind, $schema, $name, $ddl, '-- '.$info['body'], $this->facts([
            'arguments' => $arguments !== '' ? $arguments : '—',
            'returns' => $kind === ObjectKind::Function ? $info['returns'] : null,
            'deterministic' => $info['is_deterministic'],
            'data_access' => $info['sql_data_access'],
            'security' => $info['security_type'],
            'comment' => $info['routine_comment'],
            'definer' => $info['definer'],
        ]));
    }

    private function trigger(Connection $connection, string $schema, string $name): ?ObjectDefinition
    {
        $info = $this->row($connection->selectOne(
            'select trigger_name as name, event_object_table as tbl, action_timing as timing, event_manipulation as event, action_order, definer, action_statement as body
             from information_schema.triggers where trigger_schema = ? and trigger_name = ?',
            [$schema, $name],
        ));

        if ($info === []) {
            return null;
        }

        $name = (string) $info['name'];
        $ddl = $this->showCreate($connection, 'TRIGGER', $schema, $name, 'sql original statement');

        return $this->replaceable(ObjectKind::Trigger, $schema, $name, $ddl, '-- '.$info['body'], $this->facts([
            'table' => $info['tbl'],
            'timing' => $info['timing'],
            'event' => $info['event'],
            'order' => $info['action_order'],
            'definer' => $info['definer'],
        ]), [['kind' => 'table', 'schema' => $schema, 'name' => (string) $info['tbl'], 'qualifier' => null]]);
    }

    private function event(Connection $connection, string $schema, string $name): ?ObjectDefinition
    {
        $info = $this->row($connection->selectOne(
            'select event_name as name, event_type, execute_at, interval_value, interval_field, starts, ends, status, on_completion, event_comment, definer, event_definition as body
             from information_schema.events where event_schema = ? and event_name = ?',
            [$schema, $name],
        ));

        if ($info === []) {
            return null;
        }

        $name = (string) $info['name'];
        $ddl = $this->showCreate($connection, 'EVENT', $schema, $name, 'create event');

        return $this->replaceable(ObjectKind::Event, $schema, $name, $ddl, '-- '.$info['body'], $this->facts([
            'schedule' => $info['event_type'] === 'ONE TIME' ? 'AT '.$info['execute_at'] : 'EVERY '.$info['interval_value'].' '.$info['interval_field'],
            'starts' => $info['starts'],
            'ends' => $info['ends'],
            'status' => $info['status'],
            'on_completion' => $info['on_completion'],
            'comment' => $info['event_comment'],
            'definer' => $info['definer'],
        ]));
    }

    /**
     * Routine, Trigger oder Ereignis: Vorlage ohne DEFINER, wo der Treiber es kann mit OR REPLACE; ohne Recht auf
     * `SHOW CREATE` nur der Rumpf aus information_schema und nichts zum Ändern.
     *
     * @param  list<array{key: string, value: string}>  $facts
     * @param  list<array{kind: 'table'|'function', schema: string, name: string, qualifier: string|null}>  $references
     */
    private function replaceable(ObjectKind $kind, string $schema, string $name, ?string $ddl, string $fallback, array $facts, array $references = []): ObjectDefinition
    {
        if ($ddl === null) {
            return new ObjectDefinition($kind, $schema, $name, null, $fallback, null, facts: $facts, references: $references);
        }

        $own = DefinitionHead::withoutDefiner($ddl, $this->driver());
        $editable = $this->replacesInPlace($kind) ? DefinitionHead::withOrReplace($own, $this->driver()) : $own;

        return new ObjectDefinition($kind, $schema, $name, null, $this->statement($ddl), $this->statement($editable), $own, $facts, references: $references);
    }

    /**
     * `SHOW CREATE …` — null, wenn das Konto die Definition nicht sehen darf (die Spalte ist dann NULL).
     */
    private function showCreate(Connection $connection, string $keyword, string $schema, string $name, string $column): ?string
    {
        try {
            // Bezeichner aus dem Katalog, je Segment gequotet.
            $row = $this->row($connection->selectOne('show create '.$keyword.' '.$this->qualified($schema, $name)));
        } catch (Throwable) {
            return null;
        }

        $ddl = $row[$column] ?? null;

        return $ddl === null || trim($ddl) === '' ? null : $ddl;
    }

    /** Mit Strichpunkt am Ende, wie man es kopieren will. */
    protected function statement(?string $sql): string
    {
        $sql = rtrim((string) $sql);

        return str_ends_with($sql, ';') ? $sql : $sql.';';
    }

    protected function driver(): DatabaseDriver
    {
        return DatabaseDriver::MySql;
    }
}
