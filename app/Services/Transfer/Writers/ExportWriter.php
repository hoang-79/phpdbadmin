<?php

namespace App\Services\Transfer\Writers;

use App\Services\Transfer\ExportTable;

/**
 * Schreibt einen Export Zeile für Zeile in einen Strom — nie die ganze Datei im Speicher (E-01, F-8).
 */
interface ExportWriter
{
    public function begin(): void;

    public function beginTable(ExportTable $table): void;

    /** Braucht dieser Export die Zeilen der Tabelle (SQL «nur Struktur» und Sichten im SQL-Export nicht)? */
    public function wantsRows(ExportTable $table): bool;

    /**
     * @param  array<string, mixed>  $row
     */
    public function row(array $row): void;

    public function endTable(): void;

    public function finish(): void;
}
