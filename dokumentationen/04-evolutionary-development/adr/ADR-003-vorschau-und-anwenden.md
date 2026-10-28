# ADR-003 — Vorschau aus Blueprint::toSql(), Anwenden je Treiber

| Feld | Wert |
|---|---|
| Status | Angenommen |
| Datum | 28.10.2026 (Foundation; F-5) |
| Betrifft | SchemaEditor, ChangeSet |

## Kontext

Nichts soll ungesehen passieren (P-02). Die Feasibility belegt: `toSql()` ist auf allen vier genau das, was läuft; `pretend()` liefert für DML eine gute Vorschau, für SQLite-DDL aber eine kaputte. Naiv in einer Transaktion angewendet, wirft Laravel auf MySQL/MariaDB «There is no active transaction» (DDL beendet Transaktionen implizit), und **SQLite löscht beim Umbau einer Elterntabelle alle abhängigen Zeilen** (0 von 2), weil `PRAGMA foreign_keys` in einer Transaktion nicht wirkt.

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| A — alles in einer Transaktion | einfach, scheinbar sicher | auf drei von vier Treibern falsch, auf SQLite Datenverlust |
| **B — Verfahren je Treiber** | auf allen vier belegt korrekt (2 von 2 Kindern bleiben) | drei Wege, die Vorschau muss sie erklären |
| C — Strukturänderungen nur über eigenes SQL | keine Abhängigkeit von Laravel | baut die Grammatiken nach, die SQLite-Umbau schon können |

## Entscheid

**Option B.** Vorschau von Strukturänderungen aus `Blueprint::toSql()`, von Datenänderungen aus `pretend()`. Anwenden: MySQL/MariaDB Anweisung für Anweisung ohne Transaktion (Vorschau: «nicht rückrollbar»); PostgreSQL in einer Transaktion; SQLite nach sqlite.org: Fremdschlüssel ausserhalb aus, Umbau in der Transaktion, `foreign_key_check`, Commit, ein. Datenänderungen auf allen vier in einer Transaktion, vorher Sicherung für Rückgängig.

## Konsequenzen

- Tests für Strukturänderungen immer mit Elterntabelle und abhängigen Zeilen.
- Bei MySQL/MariaDB meldet die Oberfläche nach einem Fehler, welche Schritte schon gelaufen sind.
- Lektion «SQLite-Tabellenumbau nie in eigener Transaktion» im Vault.

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
