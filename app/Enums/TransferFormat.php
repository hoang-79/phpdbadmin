<?php

namespace App\Enums;

/**
 * Dateiformat eines Auftrags (E-01 bis E-04). Exportieren lässt sich in CSV, JSON, SQL und Excel; importieren CSV
 * (mit Zuordnung) und SQL (Anweisungen ausführen) — JSON und Excel nur als Export. Sicherungen (E-03): SQL von
 * mysqldump/mariadb-dump bzw. pg_dump im Textformat, das Archiv von pg_dump (Custom-Format) und die SQLite-Datei.
 */
enum TransferFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
    case Sql = 'sql';
    case Xlsx = 'xlsx';
    case PgDump = 'pgdump';
    case SqliteFile = 'sqlite';

    /** Formate, die der Export anbietet (die übrigen gehören zu Sicherungen). */
    public const array EXPORTS = ['csv', 'json', 'sql', 'xlsx'];

    public function extension(): string
    {
        return match ($this) {
            self::PgDump => 'dump',
            default => $this->value,
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Json => 'application/json',
            self::Sql => 'application/sql; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::PgDump => 'application/octet-stream',
            self::SqliteFile => 'application/vnd.sqlite3',
        };
    }

    public function canImport(): bool
    {
        return $this === self::Csv || $this === self::Sql;
    }
}
