<?php

namespace App\Dialects;

use App\Connections\ConnectionProblem;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Enums\MaintenanceOperation;
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
 * @phpstan-type ServerFact array{key: string, value: string|int, kind: 'text'|'bytes'|'number'|'datetime'}
 * @phpstan-type DatabaseSize array{name: string, size: int|null, charset: string|null, collation: string|null}
 * @phpstan-type ServerOverview array{version: string, uptime: int|null, charset: string|null, collation: string|null, facts: list<ServerFact>, databases: list<DatabaseSize>, schemas: list<array{name: string, size: int|null}>|null}
 * @phpstan-type ServerProcess array{id: int, user: string, host: string|null, program: string|null, database: string|null, command: string|null, state: string|null, seconds: int|null, statement: string|null, own: bool}
 * @phpstan-type ServerSetting array{name: string, value: string, description: string|null}
 * @phpstan-type ServerAccount array{name: string, host: string|null, attributes: list<string>, grants: list<string>, own: bool}
 * @phpstan-type MaintenanceResult array{table: string|null, status: string, message: string}
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

    /**
     * Datenbanken auf dem Server, wo sie über Schemas stehen (PostgreSQL); null, wo Datenbank und Schema dasselbe
     * sind (MySQL, MariaDB) oder es nur die Datei gibt (SQLite).
     *
     * @return list<string>|null
     */
    public function databases(Connection $connection): ?array;

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
     * Trigger einer Tabelle mit Zeitpunkt, Ereignis und Definition — Laravel liest sie nicht (F-1).
     *
     * @return list<array{name: string, timing: string, event: string, definition: string}>
     */
    public function triggers(Connection $connection, string $schema, string $table): array;

    /**
     * Fremdschlüssel anderer Tabellen, die auf diese Tabelle zeigen («Verwiesen von», Wireframe 08).
     *
     * @return list<array{name: string, table: string, columns: list<string>, foreignColumns: list<string>, onDelete: string}>
     */
    public function referencingForeignKeys(Connection $connection, string $schema, string $table): array;

    /**
     * DDL einer Tabelle oder Sicht zum Ansehen und Kopieren (S-01): MySQL und MariaDB vom Server, SQLite aus dem
     * Katalog der Datei, PostgreSQL aus dem Katalog zusammengesetzt (PostgreSQL kennt kein SHOW CREATE TABLE).
     */
    public function ddl(Connection $connection, string $schema, string $object, bool $isView): string;

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
     * Titel und Empfehlung immer auf Deutsch (`__('…', locale: 'de')`): So werden sie mit der Verbindung gespeichert;
     * übersetzt wird bei der Ausgabe (Translations::accountWarnings).
     *
     * @return list<AccountWarning>
     */
    public function accountWarnings(Connection $connection): array;

    /*
    |--------------------------------------------------------------------------
    | Server-Verwaltung (W-01 bis W-05)
    |--------------------------------------------------------------------------
    |
    | Lesen geht über jede Verbindung; was ändert, plant der Dialekt nur als Anweisungen (PlannedStatement) —
    | ausgeführt wird erst nach Vorschau und Bestätigung über eine Verbindung ohne Schreibschutz (ServerChanges).
    | Bezeichner sind vorher gegen den Katalog geprüft und werden hier je Segment gequotet, Zeichenketten über
    | PDO::quote, Rechte nur aus der festen Liste.
    |
    */

    /**
     * Server-Übersicht (W-01): Version, Laufzeit in Sekunden, Zeichensatz und Kollation als Vorgabe des Servers,
     * Kennzahlen und die Grösse je Datenbank (PostgreSQL zusätzlich je Schema der geöffneten Datenbank). SQLite:
     * Datei, Seitengrösse, Journal-Modus und Version der Bibliothek, keine Laufzeit.
     *
     * @return ServerOverview
     */
    public function serverOverview(Connection $connection): array;

    /** Kennung der eigenen Sitzung (CONNECTION_ID(), pg_backend_pid()); null ohne Server (SQLite). */
    public function sessionId(Connection $connection): ?int;

    /**
     * Prozesse und Sitzungen des Servers (W-02), die eigene markiert; null, wo es keine Serverprozesse gibt (SQLite).
     *
     * @return list<ServerProcess>|null
     */
    public function processes(Connection $connection): ?array;

    /**
     * Anweisung, die einen Prozess beendet — nur die laufende Anweisung oder die ganze Sitzung (MySQL/MariaDB
     * KILL QUERY bzw. KILL, PostgreSQL pg_cancel_backend bzw. pg_terminate_backend); null bei SQLite.
     */
    public function endProcessStatement(int $id, bool $wholeSession): ?PlannedStatement;

    /** Beendet den Prozess; false, wenn es ihn nicht (mehr) gibt. */
    public function endProcess(Connection $connection, int $id, bool $wholeSession): bool;

    /**
     * Einstellungen des Servers (W-03, nur lesen): SHOW GLOBAL VARIABLES, pg_settings, lesbare PRAGMAs.
     *
     * @return list<ServerSetting>
     */
    public function variables(Connection $connection): array;

    /**
     * Zustand und Zähler (W-03, nur lesen): SHOW GLOBAL STATUS, Statistik der Datenbank, Optionen der Bibliothek.
     *
     * @return list<ServerSetting>
     */
    public function statusValues(Connection $connection): array;

    /** Gehört zum Konto ein Host ('app'@'%')? MySQL und MariaDB ja, PostgreSQL nein. */
    public function accountsHaveHosts(): bool;

    /**
     * Konten bzw. Rollen mit ihren Rechten (W-04); null bei SQLite (keine Konten).
     *
     * @return list<ServerAccount>|null
     */
    public function accounts(Connection $connection): ?array;

    /** Das Konto, mit dem phpdbadmin verbunden ist; null bei SQLite. */
    public function currentAccount(Connection $connection): ?AccountName;

    /**
     * Die üblichen Rechte je Ebene (Schlüssel: PrivilegeLevel) — nur diese lassen sich erteilen oder entziehen. Leer
     * bei SQLite.
     *
     * @return array<string, list<string>>
     */
    public function privilegeChoices(): array;

    /**
     * Konto anlegen, mit Passwort; in der Vorschau ist das Passwort maskiert.
     *
     * @return list<PlannedStatement>
     */
    public function createAccountStatements(Connection $connection, AccountName $account, string $password): array;

    /**
     * @return list<PlannedStatement>
     */
    public function dropAccountStatements(Connection $connection, AccountName $account): array;

    /**
     * GRANT bzw. REVOKE auf Server, Datenbank, Schema oder Tabelle.
     *
     * @param  list<string>  $privileges  aus privilegeChoices() der Ebene
     * @return list<PlannedStatement>
     */
    public function privilegeStatements(Connection $connection, bool $grant, AccountName $account, PrivilegeTarget $target, array $privileges): array;

    /**
     * Was der Treiber an Wartung kann (W-05).
     *
     * @return list<MaintenanceOperation>
     */
    public function maintenanceOperations(): array;

    /** Geht die Wartung auch für einzelne Tabellen? SQLite: VACUUM nur für die ganze Datei. */
    public function maintenanceForTables(MaintenanceOperation $operation): bool;

    /**
     * Anweisungen für die Wartung einer Tabellenauswahl oder des ganzen Schemas (null).
     *
     * @param  list<string>|null  $tables
     * @return list<PlannedStatement>
     */
    public function maintenanceStatements(Connection $connection, MaintenanceOperation $operation, string $schema, ?array $tables): array;

    /**
     * Führt eine Anweisung der Wartung aus und liefert das Ergebnis je Tabelle.
     *
     * @return list<MaintenanceResult>
     */
    public function runMaintenance(Connection $connection, PlannedStatement $statement): array;

    /**
     * Statistik einer Tabelle aktualisieren (E-06): nach eigenen Importen und auf Knopfdruck — ohne sie schätzen die
     * Server die Zeilenzahl falsch und sortieren MySQL und MariaDB über einen Index hundertmal langsamer (F-3).
     */
    public function analyze(Connection $connection, string $schema, string $table): void;
}
