# 04 — Evolutionary Development

> **PDF der Phase:** [evolutionary-development.pdf](evolutionary-development.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 04`).

Timebox-Protokolle (`timebox-nn-titel.md`) und Architekturentscheide (`adr/ADR-nnn-titel.md`: Kontext → Optionen → Entscheid → Konsequenzen).

## Timeboxes

| TB | Inhalt | Anforderungen | Stand |
|---|---|---|---|
| [**00 Fundament**](timebox-00-fundament.md) | Starter-Kit bereinigt (kein Passwort, kein Dashboard, kein Profil); Anmeldung mit Code per Mail und Passkey; Einladung; Ersteinrichtung per Konsole mit Link und Passkey-Onboarding; App-Datenbank; Verbindungen mit Test, Verschlüsselung und erlaubten Hosts; Dialekt-Schicht mit Vertragstest auf vier Datenbanken; lokales Prüfskript, Workflow sparsam | A-01 bis A-06, A-09, V-01 bis V-03, V-05, B-01 bis B-03, B-06, B-08 | ✅ 28.10.2026 — offen: Touch ID von Hand (A-03), erster Lauf des Workflows auf GitHub |
| 01 Katalog und Navigator | siehe [Timebox-Plan](../03-foundation/timebox-plan.md#1-die-dreizehn-timeboxes) | O-01, O-04, S-01, V-04, N-06, V-06, V-10, O-06, N-04 | als Nächstes |
| [**07 Konten, Rechte, Protokoll**](timebox-07-konten-rechte-protokoll.md) | Policy je Verbindung (`view`, `write`, `manage`, `share`); Teilen; Verwaltung der Konten und Einladungen; Protokoll mit Filtern, Maskierung und Frist; dazu Favoriten und «zuletzt geöffnet» (V-06) | B-04, A-07, V-09, A-05, A-06, V-06 | ✅ 29.10.2026 auf eigenem Zweig — offen: Browser-Durchlauf nach dem Zusammenführen |

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

**Übernahme aus der Feasibility (erledigt in TB 00):** Die Proben F-1, F-7 und Teile von F-2 sind Vertragstests der Dialekt-Schicht (`tests/Feature/Dialects`); die Probe-Seiten `routes/probe.php`, `resources/js/pages/probe/*` bleiben lokal hinter der Anmeldung, bis Raster (TB 01) und SQL-Editor (TB 05) sie ersetzen.
