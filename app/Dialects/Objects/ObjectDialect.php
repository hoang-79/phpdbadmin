<?php

namespace App\Dialects\Objects;

use App\Enums\ObjectKind;
use Illuminate\Database\Connection;

/**
 * Objekte eines Schemas neben den Tabellen (S-06) und die Beziehungen der Tabellen (S-07) — der Teil der
 * Dialekt-Schicht (ADR-001), der je Treiber anders ist: was es gibt, wie die Definition gelesen wird, wie gelöscht
 * und ob ersetzt werden kann. Neben dem Dialekt-Vertrag, nicht in ihm, damit der Vertrag schmal bleibt; geholt wird
 * er über ObjectDialects::for().
 *
 * Bezeichner werden je Segment selbst gequotet (nie Grammar::wrap(), das an Punkten trennt). Was ändert, plant
 * ObjectPlans; hier stehen nur die Bausteine.
 *
 * @phpstan-type DiagramColumn array{name: string, type: string, primary: bool, nullable: bool}
 * @phpstan-type DiagramTable array{name: string, columns: list<DiagramColumn>}
 * @phpstan-type DiagramRelation array{name: string, table: string, columns: list<string>, foreignTable: string, foreignColumns: list<string>}
 */
interface ObjectDialect
{
    /**
     * Die Arten, die der Treiber kennt, in der Reihenfolge des Navigators.
     *
     * @return list<ObjectKind>
     */
    public function kinds(): array;

    /**
     * Eigene Typen eines Schemas (Aufzählungen, zusammengesetzte Typen, Domänen, Bereiche) — nur PostgreSQL, sonst null.
     *
     * @return list<array{name: string, variant: string}>|null
     */
    public function types(Connection $connection, string $schema): ?array;

    /**
     * Ein Objekt mit Definition; null, wenn es das Objekt im Schema nicht gibt.
     *
     * @param  string|null  $qualifier  Tabelle eines Triggers bzw. Parameter einer Routine (PostgreSQL)
     */
    public function describe(Connection $connection, string $schema, ObjectKind $kind, string $name, ?string $qualifier = null): ?ObjectDefinition;

    /** Ersetzt der Treiber diese Art an Ort und Stelle (CREATE OR REPLACE)? Sonst: löschen und neu anlegen. */
    public function replacesInPlace(ObjectKind $kind): bool;

    /** Laufen Änderungen an der Struktur in einer Transaktion (PostgreSQL, SQLite) oder nicht (MySQL, MariaDB)? */
    public function transactionalDdl(): bool;

    /** DROP für genau dieses Objekt, Namen gequotet und mit Schema. */
    public function dropStatement(ObjectDefinition $object): string;

    /**
     * Was vor einer selbst geschriebenen Definition läuft, damit sie im gewählten Schema landet (USE bzw. search_path);
     * null, wo der Name das Schema tragen muss (SQLite).
     */
    public function contextStatement(string $schema): ?string;

    /** Vorlage für ein neues Objekt dieser Art. */
    public function template(ObjectKind $kind, string $schema): string;

    /**
     * Tabellen mit Spalten und die Fremdschlüssel zwischen ihnen für das Beziehungsdiagramm (S-07) — je Treiber
     * mit wenigen Abfragen für das ganze Schema, nicht je Tabelle.
     *
     * @return array{tables: list<DiagramTable>, relations: list<DiagramRelation>}
     */
    public function diagram(Connection $connection, string $schema): array;
}
