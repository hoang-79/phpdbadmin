<?php

namespace App\Enums;

/**
 * Art einer Spalte (O-04, Wireframe 08): neben dem Typ im Original eine Einordnung, die für alle vier Datenbanken
 * gleich heisst — `bigint unsigned`, `int8` und `INTEGER` sind alle «Zahl».
 */
enum ColumnKind: string
{
    case Number = 'number';
    case Text = 'text';
    case Choice = 'choice';
    case Boolean = 'boolean';
    case DateTime = 'datetime';
    case Json = 'json';
    case Binary = 'binary';
    case Identifier = 'identifier';
    case Geometry = 'geometry';
    case List = 'list';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Number => __('Zahl'),
            self::Text => __('Text'),
            self::Choice => __('Auswahl'),
            self::Boolean => __('Wahrheitswert'),
            self::DateTime => __('Datum und Zeit'),
            self::Json => 'JSON',
            self::Binary => __('Binär'),
            self::Identifier => __('Kennung'),
            self::Geometry => __('Geometrie'),
            self::List => __('Liste'),
            self::Other => __('Sonstiges'),
        };
    }

    /**
     * @param  string  $typeName  Name des Typs ohne Länge («varchar», «int8», «_int4»)
     * @param  string  $type  vollständiger Typ («tinyint(1)», «character varying(255)», «integer[]»)
     */
    public static function of(string $typeName, string $type): self
    {
        $name = strtolower(trim($typeName));
        $full = strtolower(trim($type));

        if (str_starts_with($name, '_') || str_ends_with($full, '[]')) {
            return self::List;
        }

        if (in_array($name, ['bool', 'boolean'], true) || str_starts_with($full, 'tinyint(1)')) {
            return self::Boolean;
        }

        $base = (string) preg_replace('/[\s(].*$/', '', $name);

        return match (true) {
            in_array($base, ['enum', 'set'], true) => self::Choice,
            in_array($base, ['json', 'jsonb'], true) => self::Json,
            in_array($base, ['uuid', 'uniqueidentifier'], true) => self::Identifier,
            (bool) preg_match('/^(tiny|small|medium|big)?int(eger)?\d*$|^int[248]$|^(small|big)?serial\d*$|^(decimal|numeric|dec|fixed|float\d*|double|real|money|number)$/', $base) => self::Number,
            (bool) preg_match('/^(date|time|datetime|timestamp|timestamptz|timetz|interval|year)$/', $base) => self::DateTime,
            (bool) preg_match('/^(tiny|medium|long)?blob$|^(var)?binary$|^bytea$|^bit$/', $base) => self::Binary,
            (bool) preg_match('/^(geometry|geography|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection|line|lseg|box|path|circle)$/', $base) => self::Geometry,
            (bool) preg_match('/^(var)?char$|^character$|^bpchar$|^(tiny|medium|long)?text$|^citext$|^name$|^clob$|^string$/', $base) => self::Text,
            default => self::Other,
        };
    }
}
