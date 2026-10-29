<?php

namespace App\Enums;

/**
 * Dateiformat eines Auftrags (E-01, E-02, E-04). Exportieren lässt sich in CSV, JSON, SQL und Excel; importieren CSV
 * (mit Zuordnung) und SQL (Anweisungen ausführen) — JSON und Excel nur als Export.
 */
enum TransferFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
    case Sql = 'sql';
    case Xlsx = 'xlsx';

    /** Formate, die der Export anbietet (die übrigen gehören zu Sicherungen). */
    public const array EXPORTS = ['csv', 'json', 'sql', 'xlsx'];

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Json => 'application/json',
            self::Sql => 'application/sql; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function canImport(): bool
    {
        return $this === self::Csv || $this === self::Sql;
    }
}
