<?php

namespace App\Services\Shape;

use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;

/**
 * Schemavergleich (S-08): Quelle gegen Ziel — Tabellen, Spalten, Indizes, Fremdschlüssel.
 *
 * Gleicher Treiber: Spaltentypen werden im Original verglichen. Verschiedene Treiber: verglichen wird die Art (O-04)
 * — mit dem, was Laravel auf dem anderen Treiber daraus macht: JSON wird in SQLite zu Text, eine Aufzählung in
 * PostgreSQL und SQLite zu Text mit Check, `year` ausserhalb von MySQL zur Zahl. Längen zählen, wo beide Seiten
 * eine melden. Namen von Indizes und Fremdschlüsseln zählen nie — jeder Treiber vergibt sie anders.
 *
 * @phpstan-import-type ColumnDifference from TableDifference
 * @phpstan-import-type IndexDifference from TableDifference
 * @phpstan-import-type ForeignKeyDifference from TableDifference
 */
final class ShapeComparer
{
    public function compare(SchemaShape $source, SchemaShape $target): Comparison
    {
        $differences = [];
        $identical = 0;

        foreach ($source->tables as $table) {
            $counterpart = $target->table($table->name);

            if ($counterpart === null) {
                $differences[] = new TableDifference($table->name, Comparison::ADDED, $table, null);

                continue;
            }

            $difference = $this->table($table, $counterpart);

            if ($difference === null) {
                $identical++;
            } else {
                $differences[] = $difference;
            }
        }

        foreach ($target->tables as $table) {
            if ($source->table($table->name) === null) {
                $differences[] = new TableDifference($table->name, Comparison::REMOVED, null, $table);
            }
        }

        return new Comparison($source, $target, $differences, $identical);
    }

    /**
     * Unterschiede zweier gleichnamiger Tabellen; null, wenn sie gleich sind.
     */
    public function table(TableShape $source, TableShape $target): ?TableDifference
    {
        $columns = $this->columns($source, $target);
        $indexes = $this->indexes($source, $target);
        $foreignKeys = $this->foreignKeys($source, $target);

        if ($columns === [] && $indexes === [] && $foreignKeys === []) {
            return null;
        }

        return new TableDifference($source->name, Comparison::CHANGED, $source, $target, $columns, $indexes, $foreignKeys);
    }

    /**
     * @return list<ColumnDifference>
     */
    private function columns(TableShape $source, TableShape $target): array
    {
        $differences = [];

        foreach ($source->columns as $column) {
            $counterpart = $target->column($column->name);

            if ($counterpart === null) {
                $differences[] = ['name' => $column->name, 'status' => Comparison::ADDED, 'source' => $column, 'target' => null, 'changes' => []];

                continue;
            }

            $changes = $this->columnChanges($column, $source->driver, $counterpart, $target->driver);

            if ($changes !== []) {
                $differences[] = ['name' => $column->name, 'status' => Comparison::CHANGED, 'source' => $column, 'target' => $counterpart, 'changes' => $changes];
            }
        }

        foreach ($target->columns as $column) {
            if ($source->column($column->name) === null) {
                $differences[] = ['name' => $column->name, 'status' => Comparison::REMOVED, 'source' => null, 'target' => $column, 'changes' => []];
            }
        }

        return $differences;
    }

    /**
     * @return list<'type'|'length'|'nullable'|'default'|'autoIncrement'>
     */
    public function columnChanges(ColumnShape $source, DatabaseDriver $sourceDriver, ColumnShape $target, DatabaseDriver $targetDriver): array
    {
        $changes = [];

        if ($sourceDriver === $targetDriver) {
            if (self::normalizedType($source->type, $sourceDriver) !== self::normalizedType($target->type, $targetDriver)) {
                $changes[] = 'type';
            }
        } else {
            if (! $this->sameKind($source, $sourceDriver, $target, $targetDriver)) {
                $changes[] = 'type';
            } elseif ($source->length() !== null && $target->length() !== null && $source->length() !== $target->length()) {
                $changes[] = 'length';
            }
        }

        if ($source->nullable !== $target->nullable) {
            $changes[] = 'nullable';
        }

        if (! DefaultValue::same($source->default, $target->default, $source->kind)) {
            $changes[] = 'default';
        }

        if ($source->autoIncrement !== $target->autoIncrement) {
            $changes[] = 'autoIncrement';
        }

        return $changes;
    }

    /**
     * @return list<IndexDifference>
     */
    private function indexes(TableShape $source, TableShape $target): array
    {
        $sameFamily = self::family($source->driver) === self::family($target->driver);
        $sourceIndexes = $this->keyed($source->indexes, $sameFamily);
        $targetIndexes = $this->keyed($target->indexes, $sameFamily);
        $differences = [];

        foreach ($sourceIndexes as $signature => $index) {
            if (! isset($targetIndexes[$signature]) && ! $source->isImplicitForeignKeyIndex($index)) {
                $differences[] = ['status' => Comparison::ADDED, 'source' => $index, 'target' => null];
            }
        }

        foreach ($targetIndexes as $signature => $index) {
            if (! isset($sourceIndexes[$signature]) && ! $target->isImplicitForeignKeyIndex($index)) {
                $differences[] = ['status' => Comparison::REMOVED, 'source' => null, 'target' => $index];
            }
        }

        return $differences;
    }

    /**
     * @param  list<IndexShape>  $indexes
     * @return array<string, IndexShape>
     */
    private function keyed(array $indexes, bool $sameFamily): array
    {
        $keyed = [];

        foreach ($indexes as $index) {
            $keyed[$index->signature($sameFamily)] ??= $index;
        }

        return $keyed;
    }

    /**
     * @return list<ForeignKeyDifference>
     */
    private function foreignKeys(TableShape $source, TableShape $target): array
    {
        $targetKeys = [];
        foreach ($target->foreignKeys as $foreignKey) {
            $targetKeys[$foreignKey->signature()] ??= $foreignKey;
        }

        $differences = [];
        $seen = [];

        foreach ($source->foreignKeys as $foreignKey) {
            $signature = $foreignKey->signature();
            $seen[$signature] = true;
            $counterpart = $targetKeys[$signature] ?? null;

            if ($counterpart === null) {
                $differences[] = ['status' => Comparison::ADDED, 'source' => $foreignKey, 'target' => null];
            } elseif (! $foreignKey->sameRules($counterpart)) {
                $differences[] = ['status' => Comparison::CHANGED, 'source' => $foreignKey, 'target' => $counterpart];
            }
        }

        foreach ($targetKeys as $signature => $foreignKey) {
            if (! isset($seen[$signature])) {
                $differences[] = ['status' => Comparison::REMOVED, 'source' => null, 'target' => $foreignKey];
            }
        }

        return $differences;
    }

    /**
     * Gleiche Art über Treiber hinweg: gleich gelesen, oder eine Seite wird auf dem Treiber der anderen zu genau dieser
     * Art, wenn Laravel sie dort anlegt.
     */
    private function sameKind(ColumnShape $source, DatabaseDriver $sourceDriver, ColumnShape $target, DatabaseDriver $targetDriver): bool
    {
        return $source->kind === $target->kind
            || self::kindOn($source->laravel->type, $targetDriver) === $target->kind
            || self::kindOn($target->laravel->type, $sourceDriver) === $source->kind;
    }

    /**
     * Die Art, die eine Spalte von Laravel auf diesem Treiber bekommt.
     */
    public static function kindOn(string $laravelType, DatabaseDriver $driver): ColumnKind
    {
        $mysqlFamily = self::family($driver) === 'mysql';

        return match ($laravelType) {
            'json', 'jsonb' => $driver === DatabaseDriver::Sqlite ? ColumnKind::Text : ColumnKind::Json,
            'enum', 'set' => $mysqlFamily ? ColumnKind::Choice : ColumnKind::Text,
            'uuid' => in_array($driver, [DatabaseDriver::Postgres, DatabaseDriver::MariaDb], true) ? ColumnKind::Identifier : ColumnKind::Text,
            'ipAddress', 'macAddress' => $driver === DatabaseDriver::Postgres ? ColumnKind::Other : ColumnKind::Text,
            'boolean' => ColumnKind::Boolean,
            'year' => $mysqlFamily ? ColumnKind::DateTime : ColumnKind::Number,
            'tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger', 'decimal', 'float', 'double' => ColumnKind::Number,
            'string', 'char', 'tinyText', 'text', 'mediumText', 'longText' => ColumnKind::Text,
            'date', 'time', 'timeTz', 'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz' => ColumnKind::DateTime,
            'binary' => ColumnKind::Binary,
            'geometry', 'geography' => ColumnKind::Geometry,
            default => ColumnKind::Other,
        };
    }

    /**
     * Typ im Original, so geglättet, dass zwei Datenbanken desselben Treibers gleich schreiben: klein, einfache
     * Leerzeichen, ohne Anzeigebreite bei Ganzzahlen (MariaDB «int(11)») — ausser «tinyint(1)», das ist ein
     * Wahrheitswert.
     */
    public static function normalizedType(string $type, DatabaseDriver $driver): string
    {
        $type = strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));

        if (self::family($driver) === 'mysql' && ! str_starts_with($type, 'tinyint(1)')) {
            $type = (string) preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint|year)\(\d+\)/', '$1', $type);
        }

        return $type;
    }

    /** MySQL und MariaDB können dasselbe (Volltext, räumliche Indizes, Aufzählungen). */
    public static function family(DatabaseDriver $driver): string
    {
        return in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) ? 'mysql' : $driver->value;
    }
}
