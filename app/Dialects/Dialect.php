<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use Closure;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Vertrag der Dialekt-Schicht (B-02, ADR-001): alles, was je Treiber anders ist. Laravel-Introspektion zuerst, das
 * Schema immer ausdrücklich; der Dialekt ergänzt, was Laravel nicht einheitlich kann. Belegt durch die Vertragstests
 * gegen alle vier Treiber (tests/Feature/Dialects).
 *
 * @phpstan-type TableSummary array{name: string, type: 'table'|'view', rows: int|null, size: int|null, comment: string|null}
 * @phpstan-type AccountWarning array{key: string, title: string, recommendation: string}
 */
interface Dialect
{
    public function driver(): DatabaseDriver;

    /**
     * Konfiguration für DB::build(): Sitzung in UTC, Zeitlimit beim Verbinden, Verschlüsselung, Schreibschutz beim
     * Öffnen (SQLite) bzw. für die Sitzung (Server).
     *
     * @param  string|null  $address  die geprüfte Adresse (HostPolicy) bzw. der aufgelöste Dateipfad
     * @return array<string, mixed>
     */
    public function connectionConfig(ConnectionSettings $settings, ?string $address, int $connectTimeout, bool $readOnly = false): array;

    /**
     * Sitzung nach dem Verbinden einrichten — was die Konfiguration nicht kann (PostgreSQL: Schreibschutz der Sitzung).
     */
    public function prepareSession(Connection $connection, bool $readOnly): void;

    /** Version des Servers bzw. der SQLite-Bibliothek, ohne Zusätze («10.11.6», «18.0», «8.4.11»). */
    public function serverVersion(Connection $connection): string;

    /** Fehler beim Verbinden in eine für Menschen unterscheidbare Art übersetzen (V-02, F-2). */
    public function describeConnectionError(Throwable $error): ConnectionProblem;

    /**
     * Schemas ohne die des Systems (MySQL/MariaDB: Datenbanken; SQLite: main und angehängte).
     *
     * @return list<string>
     */
    public function schemas(Connection $connection): array;

    public function defaultSchema(Connection $connection): ?string;

    /**
     * Tabellen und Sichten eines Schemas mit geschätzter Zeilenzahl (null, wo der Treiber keine Schätzung kennt).
     *
     * @return list<TableSummary>
     */
    public function tables(Connection $connection, string $schema): array;

    /**
     * Check-Constraints einer Tabelle — Laravel liest sie nicht (F-1).
     *
     * @return list<array{name: string, definition: string}>
     */
    public function checkConstraints(Connection $connection, string $schema, string $table): array;

    /**
     * Trigger einer Tabelle — Laravel liest sie nicht (F-1).
     *
     * @return list<string>
     */
    public function triggers(Connection $connection, string $schema, string $table): array;

    /**
     * Gespeicherte Funktionen und Prozeduren eines Schemas; null, wo der Treiber keine kennt (SQLite).
     *
     * @return list<array{name: string, kind: 'function'|'procedure'}>|null
     */
    public function routines(Connection $connection, string $schema): ?array;

    /**
     * Alle Trigger eines Schemas mit ihrer Tabelle.
     *
     * @return list<array{name: string, table: string}>
     */
    public function schemaTriggers(Connection $connection, string $schema): array;

    /**
     * Geplante Ereignisse (nur MySQL und MariaDB); null bei den übrigen Treibern.
     *
     * @return list<string>|null
     */
    public function events(Connection $connection, string $schema): ?array;

    /**
     * Sequenzen (nur PostgreSQL); null bei den übrigen Treibern.
     *
     * @return list<string>|null
     */
    public function sequences(Connection $connection, string $schema): ?array;

    /** Kann der Treiber eine einzelne Anweisung zeitlich begrenzen? SQLite nicht (F-2, Q-09). */
    public function supportsStatementTimeout(): bool;

    /** Zeitlimit je Anweisung für diese Sitzung; ohne Wirkung, wo der Treiber keines kennt. */
    public function setStatementTimeout(Connection $connection, int $milliseconds): void;

    /** Wurde die Anweisung vom Zeitlimit abgebrochen? MySQL bricht SELECT still ab (F-2) — dort hilft nur der Merker. */
    public function isTimeout(Throwable $error): bool;

    /**
     * Schreibschutz-Wächter (V-04, F-7): führt genau eine Anweisung in einer Nur-lesen-Transaktion aus, die immer
     * zurückgerollt wird. SQLite ist schon beim Öffnen schreibgeschützt.
     *
     * @template T
     *
     * @param  Closure(): T  $statement
     * @return T
     */
    public function runReadOnly(Connection $connection, Closure $statement): mixed;

    /** Hat der Schreibschutz die Anweisung abgewiesen? */
    public function isReadOnlyViolation(Throwable $error): bool;

    /**
     * Rechte des Kontos, die über die Arbeit mit Daten hinausgehen (V-10, F-7): Superuser, Dateien auf dem Server.
     *
     * @return list<AccountWarning>
     */
    public function accountWarnings(Connection $connection): array;
}
