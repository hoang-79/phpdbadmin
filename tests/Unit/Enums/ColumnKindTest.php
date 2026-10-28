<?php

namespace Tests\Unit\Enums;

use App\Enums\ColumnKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Art einer Spalte (O-04): gleiche Einordnung für die Typen aller vier Datenbanken.
 */
class ColumnKindTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: ColumnKind}>
     */
    public static function types(): array
    {
        return [
            'MySQL bigint unsigned' => ['bigint', 'bigint unsigned', ColumnKind::Number],
            'PostgreSQL int8' => ['int8', 'bigint', ColumnKind::Number],
            'SQLite INTEGER' => ['integer', 'integer', ColumnKind::Number],
            'Dezimalzahl' => ['decimal', 'decimal(10,2)', ColumnKind::Number],
            'PostgreSQL numeric' => ['numeric', 'numeric(10,2)', ColumnKind::Number],
            'MySQL tinyint(1) als Wahrheitswert' => ['tinyint', 'tinyint(1)', ColumnKind::Boolean],
            'MySQL tinyint als Zahl' => ['tinyint', 'tinyint unsigned', ColumnKind::Number],
            'PostgreSQL bool' => ['bool', 'boolean', ColumnKind::Boolean],
            'varchar' => ['varchar', 'varchar(255)', ColumnKind::Text],
            'PostgreSQL character varying' => ['varchar', 'character varying(255)', ColumnKind::Text],
            'PostgreSQL bpchar' => ['bpchar', 'character(3)', ColumnKind::Text],
            'longtext' => ['longtext', 'longtext', ColumnKind::Text],
            'enum' => ['enum', "enum('offen','bezahlt')", ColumnKind::Choice],
            'json' => ['json', 'json', ColumnKind::Json],
            'jsonb' => ['jsonb', 'jsonb', ColumnKind::Json],
            'timestamp' => ['timestamp', 'timestamp', ColumnKind::DateTime],
            'PostgreSQL timestamptz' => ['timestamptz', 'timestamp with time zone', ColumnKind::DateTime],
            'SQLite datetime' => ['datetime', 'datetime', ColumnKind::DateTime],
            'longblob' => ['longblob', 'longblob', ColumnKind::Binary],
            'bytea' => ['bytea', 'bytea', ColumnKind::Binary],
            'uuid' => ['uuid', 'uuid', ColumnKind::Identifier],
            'PostgreSQL Liste' => ['_int4', 'integer[]', ColumnKind::List],
            'point' => ['point', 'point', ColumnKind::Geometry],
            'unbekannt' => ['tsvector', 'tsvector', ColumnKind::Other],
            'SQLite ohne Typ' => ['', '', ColumnKind::Other],
        ];
    }

    #[DataProvider('types')]
    public function test_maps_original_type_to_the_same_kind_for_every_driver(string $typeName, string $type, ColumnKind $kind): void
    {
        $this->assertSame($kind, ColumnKind::of($typeName, $type));
    }
}
