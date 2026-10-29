<?php

namespace App\Services\Shape;

use App\Enums\DatabaseDriver;

/**
 * Was Änderungsskript (S-08) und Migration (S-09) gemeinsam brauchen, um Tabellen auf einem anderen Treiber
 * anzulegen: Spalten von Fremdschlüsseln an ihr Ziel angleichen, Tabellen nach Abhängigkeit ordnen, Namen für
 * Indizes und Schlüssel vergeben, Laravel-Spalten an den Treiber anpassen.
 */
final class PortableColumns
{
    /** Höchstlänge für Namen von Indizes und Schlüsseln — MySQL erlaubt 64, PostgreSQL 63 Zeichen. */
    private const int MAX_NAME = 60;

    /**
     * Spalten von Fremdschlüsseln mit dem Ganzzahltyp ihres Ziels: MySQL verlangt genau gleiche Typen, und aus
     * PostgreSQL oder SQLite kommt ein Autoinkrement-Schlüssel als «unsigned», die zeigende Spalte aber nicht.
     *
     * @param  SchemaShape|list<TableShape>  $tables
     * @return array<string, array<string, LaravelColumn>> klein geschrieben: Tabelle → Spalte → Typ
     */
    public static function referenceTypes(SchemaShape|array $tables): array
    {
        $byName = [];
        foreach ($tables instanceof SchemaShape ? $tables->tables : $tables as $table) {
            $byName[strtolower($table->name)] = $table;
        }

        $types = [];

        foreach ($byName as $key => $table) {
            foreach ($table->foreignKeys as $foreignKey) {
                $target = $byName[strtolower($foreignKey->foreignTable)] ?? null;

                foreach ($foreignKey->columns as $position => $column) {
                    $referenced = $target?->column($foreignKey->foreignColumns[$position] ?? '');
                    $own = $table->column($column);

                    if ($referenced !== null && $own !== null && $referenced->laravel->isInteger() && $own->laravel->isInteger()) {
                        $types[$key][strtolower($column)] = $referenced->laravel->forReference();
                    }
                }
            }
        }

        return $types;
    }

    /**
     * Tabellen so geordnet, dass jede nach den Tabellen kommt, auf die ihre Fremdschlüssel zeigen (Ringe in der
     * gegebenen Reihenfolge).
     *
     * @param  list<TableShape>  $tables
     * @return list<TableShape>
     */
    public static function dependenciesFirst(array $tables): array
    {
        $pending = [];
        foreach ($tables as $table) {
            $pending[strtolower($table->name)] = $table;
        }

        $ordered = [];
        $placed = [];

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $key => $table) {
                $waiting = array_any($table->foreignKeys, fn (ForeignKeyShape $foreignKey): bool => ($target = strtolower($foreignKey->foreignTable)) !== $key
                    && isset($pending[$target]) && ! isset($placed[$target]));

                if (! $waiting) {
                    $ordered[] = $table;
                    $placed[$key] = true;
                    unset($pending[$key]);
                    $progress = true;
                }
            }

            if (! $progress) {
                $key = array_key_first($pending);
                $ordered[] = $pending[$key];
                $placed[$key] = true;
                unset($pending[$key]);
            }
        }

        return $ordered;
    }

    /**
     * Umgekehrt: zuerst die Tabellen, die auf andere zeigen — so lassen sie sich entfernen.
     *
     * @param  list<TableShape>  $tables
     * @return list<TableShape>
     */
    public static function dependentsFirst(array $tables): array
    {
        return array_reverse(self::dependenciesFirst($tables));
    }

    /**
     * Name nach Laravels Muster («bestellungen_kunde_id_foreign»), höchstens 60 Zeichen — längere bekommen eine
     * Prüfsumme statt des Rests.
     *
     * @param  list<string>  $columns
     */
    public static function name(string $table, array $columns, string $suffix): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $table.'_'.implode('_', $columns).'_'.$suffix));

        if (strlen($name) <= self::MAX_NAME) {
            return $name;
        }

        return substr($name, 0, self::MAX_NAME - 9).'_'.substr(hash('xxh128', $name), 0, 8);
    }

    /**
     * Laravel-Spalte für den Treiber des Ziels: Länge für `string`, wo der Treiber eine braucht; `set` gibt es nur in
     * MySQL und MariaDB.
     */
    public static function forDriver(LaravelColumn $column, DatabaseDriver $driver): LaravelColumn
    {
        $mysqlFamily = in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true);

        if ($column->type === 'string' && ($column->parameters['length'] ?? null) === null && $driver !== DatabaseDriver::Postgres) {
            return $column->with(['length' => LaravelTypes::DEFAULT_STRING_LENGTH]);
        }

        if ($column->type === 'set' && ! $mysqlFamily) {
            return new LaravelColumn('string', ['length' => LaravelTypes::DEFAULT_STRING_LENGTH], __('Typ «set» gibt es nur in MySQL und MariaDB — als Text übernommen.'));
        }

        return $column;
    }
}
