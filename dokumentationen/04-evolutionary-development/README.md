# 04 — Evolutionary Development

> **PDF der Phase:** [evolutionary-development.pdf](evolutionary-development.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 04`).

📋 **Platzhalter** — Timebox-Protokolle (`timebox-nn-titel.md`) und Architekturentscheide (`adr/ADR-nnn-titel.md`: Kontext → Optionen → Entscheid → Konsequenzen).

## Geplante erste Timebox

| TB | Inhalt | Anforderungen |
|---|---|---|
| **00 Fundament** — Scheiben im [Timebox-Plan § 2](../03-foundation/timebox-plan.md#2-tb-00-in-scheiben) | Starter-Kit bereinigen: Passwort-Anmeldung, Registrierung, Passwort-Reset und -Bestätigung, Zwei-Faktor, E-Mail-Bestätigung, **Dashboard** und **Profil** entfernen; Anmeldung mit Code per Mail und Passkey; Einladung und Erst-Setup per Konsole; App-Datenbank; Verbindungen mit Test und Verschlüsselung; Dialekt-Schicht mit Vertragstest; MySQL in Herd; Tests auf vier Datenbanken; Workflow sparsam | A-01 bis A-06, A-09, V-01 bis V-03, V-05, B-01 bis B-03, B-06, B-08 |

## Architekturentscheide

Ausformuliert in der Foundation am 28.10.2026 (Kontext → Optionen → Entscheid → Konsequenzen), Grundlage [Feasibility](../02-feasibility/feasibility.md) und [Solution-Architektur](../03-foundation/solution-architecture.md):

| ADR | Entscheid | Status |
|---|---|---|
| [ADR-001](adr/ADR-001-dialekt-schicht.md) | Dialekt-Schicht: ein Vertrag, vier Umsetzungen | angenommen |
| [ADR-002](adr/ADR-002-verbindung-je-anfrage.md) | Zielverbindungen je Anfrage, Grenzen und Abbruch je Treiber | angenommen |
| [ADR-003](adr/ADR-003-vorschau-und-anwenden.md) | Vorschau aus `toSql()`, Anwenden je Treiber | angenommen |
| [ADR-004](adr/ADR-004-schreibschutz-in-schichten.md) | Schreibschutz in Schichten, eine Anweisung je Aufruf | angenommen |
| [ADR-005](adr/ADR-005-sql-editor-codemirror.md) | SQL-Editor CodeMirror 6 | angenommen |
| [ADR-006](adr/ADR-006-raster-virtualisiert.md) | Raster TanStack Virtual, Blättern über den Schlüssel | angenommen |
| [ADR-007](adr/ADR-007-export-import-in-paketen.md) | Export und Import in Paketen im Hintergrund | angenommen |
| [ADR-008](adr/ADR-008-anmeldung-ohne-passwort.md) | Anmeldung ohne Passwort | angenommen |
| [ADR-009](adr/ADR-009-app-datenbank-sqlite.md) | App-Datenbank SQLite als Vorgabe | angenommen |
| [ADR-010](adr/ADR-010-lizenz-mit.md) | Lizenz MIT | angenommen |
| [ADR-011](adr/ADR-011-php-85-mindestversion.md) | PHP 8.5 als Mindestversion | angenommen (Hoang 28.10.2026) |

**Übernahme aus der Feasibility in TB 00:** Probe-Seiten `routes/probe.php`, `resources/js/pages/probe/*` übernehmen oder entfernen; die Proben in `tests/Feature/Feasibility` werden zu Vertragstests der Dialekt-Schicht.
