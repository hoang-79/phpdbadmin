# ADR-009 — App-Datenbank: SQLite als Vorgabe

| Feld | Wert |
|---|---|
| Status | Angenommen |
| Datum | 28.10.2026 (Foundation; R-31) |
| Betrifft | Installation, Betrieb |

## Kontext

Die App-Datenbank hält Konten, Verbindungen, Verlauf und Protokoll — wenig Daten, wenige gleichzeitige Schreiber. Eine Installation neben fremden Datenbanken soll ohne zusätzlichen Server laufen.

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| **A — SQLite als Vorgabe, die anderen drei möglich** | keine Einrichtung, Sicherung = eine Datei | Schreibsperren bei vielen Parallelzugriffen (`busy_timeout`, WAL) |
| B — MariaDB/PostgreSQL vorgeschrieben | robuster bei vielen Konten | zusätzlicher Server nur für phpdbadmin |

## Entscheid

**Option A**, mit WAL und `busy_timeout`; die Testsuite läuft mit jeder der vier als App-Datenbank (B-06).

## Konsequenzen

- Regeln für Spaltentypen gelten trotzdem für alle vier (Datenmodell § 1).
- Die App-Datenbank erscheint nie als Zielverbindung (R-17).

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
