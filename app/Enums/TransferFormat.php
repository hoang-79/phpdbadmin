<?php

namespace App\Enums;

/**
 * Dateiformat eines Auftrags (E-01, E-02). Exportieren lässt sich in alle drei; importieren CSV (mit Zuordnung) und
 * SQL (Anweisungen ausführen) — JSON nur als Export.
 */
enum TransferFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
    case Sql = 'sql';

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
        };
    }

    public function canImport(): bool
    {
        return $this !== self::Json;
    }
}
