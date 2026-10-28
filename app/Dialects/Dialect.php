<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Vertrag der Dialekt-Schicht (B-02, ADR-001): alles, was je Treiber anders ist. Laravel-Introspektion zuerst;
 * der Dialekt ergänzt, was Laravel nicht einheitlich kann.
 */
interface Dialect
{
    public function driver(): DatabaseDriver;

    /**
     * Konfiguration für DB::build(): Sitzung in UTC, Zeitlimit beim Verbinden, Verschlüsselung.
     *
     * @param  string|null  $address  die geprüfte Adresse (HostPolicy) bzw. der aufgelöste Dateipfad
     * @return array<string, mixed>
     */
    public function connectionConfig(ConnectionSettings $settings, ?string $address, int $connectTimeout): array;

    /** Version des Servers bzw. der SQLite-Bibliothek, ohne Zusätze («10.11.6», «18.0», «8.4.11»). */
    public function serverVersion(Connection $connection): string;

    /** Fehler beim Verbinden in eine für Menschen unterscheidbare Art übersetzen (V-02, F-2). */
    public function describeConnectionError(Throwable $error): ConnectionProblem;
}
