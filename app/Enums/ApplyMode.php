<?php

namespace App\Enums;

/**
 * Wie eine Strukturänderung angewendet wird (ADR-003, N-03): MySQL und MariaDB schliessen bei jeder DDL die
 * Transaktion ab — dort läuft Anweisung für Anweisung; PostgreSQL rollt DDL zurück — dort läuft alles in einer
 * Transaktion; SQLite nach dem Umbauverfahren von sqlite.org (Fremdschlüssel ausserhalb aus, Umbau in der
 * Transaktion, Prüfung vor dem Abschluss, wieder ein).
 */
enum ApplyMode: string
{
    case Statements = 'statements';
    case Transaction = 'transaction';
    case Rebuild = 'rebuild';

    public static function for(DatabaseDriver $driver): self
    {
        return match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => self::Statements,
            DatabaseDriver::Postgres => self::Transaction,
            DatabaseDriver::Sqlite => self::Rebuild,
        };
    }

    /** Lässt sich ein Fehler mitten in der Folge vollständig zurückrollen? */
    public function rollsBack(): bool
    {
        return $this !== self::Statements;
    }

    /**
     * Was vor den Anweisungen läuft (nur zum Zeigen; ausgeführt wird es von SchemaChanges selbst).
     *
     * @return list<string>
     */
    public function before(): array
    {
        return match ($this) {
            self::Statements => [],
            self::Transaction => ['BEGIN'],
            self::Rebuild => ['PRAGMA foreign_keys = OFF', 'BEGIN'],
        };
    }

    /**
     * Was nach den Anweisungen läuft.
     *
     * @return list<string>
     */
    public function after(): array
    {
        return match ($this) {
            self::Statements => [],
            self::Transaction => ['COMMIT'],
            self::Rebuild => ['PRAGMA foreign_key_check', 'COMMIT', 'PRAGMA foreign_keys = ON'],
        };
    }

    /** Überschrift des Hinweises in der Vorschau (Wireframe 9). */
    public function title(DatabaseDriver $driver): string
    {
        return match ($this) {
            self::Statements => __('Nicht rückrollbar — Anweisung für Anweisung'),
            self::Transaction => __(':driver: in einer Transaktion', ['driver' => $driver->label()]),
            self::Rebuild => __('SQLite: Umbau in einer Transaktion'),
        };
    }

    public function explanation(DatabaseDriver $driver): string
    {
        return match ($this) {
            self::Statements => __(':driver schliesst bei jeder Strukturänderung die Transaktion ab. Schlägt eine Anweisung fehl, bleiben die vorherigen bestehen; phpdbadmin hält dann an und zeigt, was angewendet ist.', ['driver' => $driver->label()]),
            self::Transaction => __('Schlägt eine Anweisung fehl, wird keine angewendet — die Tabelle bleibt, wie sie war.'),
            self::Rebuild => __('Fremdschlüssel werden ausserhalb der Transaktion kurz ausgeschaltet und vor dem Abschluss geprüft. Schlägt etwas fehl, wird nichts angewendet.'),
        };
    }
}
