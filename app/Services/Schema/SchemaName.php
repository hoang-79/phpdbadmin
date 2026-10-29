<?php

namespace App\Services\Schema;

use App\Enums\DatabaseDriver;

/**
 * Namen von Schemas, Tabellen, Spalten, Indizes und Fremdschlüsseln für Strukturänderungen (S-02 bis S-05).
 *
 * Laravels Grammatik setzt Namen über wrap() ein — und zerlegt dabei an Punkten, an « as » und an «->», «*» bleibt
 * ungequotet. Ein Name, der so zerfällt, ergäbe anderes SQL als gemeint. Deshalb gilt hier eine enge Regel: kein
 * Punkt, keine Anführungszeichen, keine Steuerzeichen, kein « as », höchstens 63 Bytes (PostgreSQL kürzt längere
 * still). Was die Regel nicht erfüllt, bearbeitet man im SQL-Editor.
 */
final class SchemaName
{
    /** Längste Bezeichner in PostgreSQL (NAMEDATALEN − 1); MySQL erlaubt 64 Zeichen. */
    public const int MAX_BYTES = 63;

    /** Grund, warum der Name hier nicht geht — null, wenn er geht. */
    public static function problem(string $name): ?string
    {
        $problem = self::describe($name);

        return $problem === '' ? null : $problem;
    }

    public static function isSafe(string $name): bool
    {
        return self::problem($name) === null;
    }

    /** Grund als Text, leer, wenn der Name geht. */
    private static function describe(string $name): string
    {
        return match (true) {
            $name === '' => __('Der Name darf nicht leer sein.'),
            strlen($name) > self::MAX_BYTES => __('Der Name ist zu lang (höchstens 63 Bytes).'),
            preg_match('/[\x00-\x1F\x7F]/', $name) === 1, trim($name) !== $name => __('Der Name darf keine Steuerzeichen und keine Leerzeichen am Anfang oder Ende haben.'),
            preg_match('/[.`"\'\[\]\\\\;]/', $name) === 1 => __('Punkte, Anführungszeichen, eckige Klammern, Backslash und Semikolon sind in Namen hier nicht möglich.'),
            $name === '*', str_contains($name, '->'), preg_match('/\sas\s/i', $name) === 1 => __('Namen mit « as », «->» oder nur «*» verarbeitet phpdbadmin hier nicht.'),
            default => '',
        };
    }

    /** Ein Bezeichner in den Anführungszeichen des Treibers, das Zeichen darin verdoppelt. */
    public static function quote(DatabaseDriver $driver, string $name): string
    {
        return match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => '`'.str_replace('`', '``', $name).'`',
            default => '"'.str_replace('"', '""', $name).'"',
        };
    }

    /**
     * Tabelle mit Schema in Anführungszeichen. SQLite: «main» bleibt weg — so sieht das SQL aus wie in jeder Datei.
     */
    public static function qualified(DatabaseDriver $driver, string $schema, string $table): string
    {
        if ($driver === DatabaseDriver::Sqlite && $schema === 'main') {
            return self::quote($driver, $table);
        }

        return self::quote($driver, $schema).'.'.self::quote($driver, $table);
    }

    /**
     * Name der Tabelle für Laravels Blueprint («schema.tabelle»; SQLite «main» ohne Schema). Beide Teile sind vorher
     * mit problem() geprüft — der Punkt trennt also sicher Schema und Tabelle.
     */
    public static function forBlueprint(DatabaseDriver $driver, string $schema, string $table): string
    {
        return $driver === DatabaseDriver::Sqlite && $schema === 'main' ? $table : $schema.'.'.$table;
    }

    /**
     * Name nach Laravels Muster ({tabelle}_{spalten}_{art}); zu lang gekürzt und mit Prüfsumme eindeutig gehalten.
     *
     * @param  list<string>  $columns
     */
    public static function conventional(string $table, array $columns, string $suffix): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{N}_$]+/u', '_', mb_strtolower($table.'_'.implode('_', $columns).'_'.$suffix));

        if (strlen($name) <= self::MAX_BYTES) {
            return $name;
        }

        $hash = substr(hash('xxh32', $name), 0, 8);

        return mb_strcut($name, 0, self::MAX_BYTES - 9).'_'.$hash;
    }
}
