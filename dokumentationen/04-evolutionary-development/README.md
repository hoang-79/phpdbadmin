# 04 — Evolutionary Development

> **PDF der Phase:** [evolutionary-development.pdf](evolutionary-development.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 04`).

Timebox-Protokolle (`timebox-nn-titel.md`) und Architekturentscheide (`adr/ADR-nnn-titel.md`: Kontext → Optionen → Entscheid → Konsequenzen).

## Timeboxes

| TB | Inhalt | Anforderungen | Stand |
|---|---|---|---|
| [**00 Fundament**](timebox-00-fundament.md) | Starter-Kit bereinigt (kein Passwort, kein Dashboard, kein Profil); Anmeldung mit Code per Mail und Passkey; Einladung; Ersteinrichtung per Konsole mit Link und Passkey-Onboarding; App-Datenbank; Verbindungen mit Test, Verschlüsselung und erlaubten Hosts; Dialekt-Schicht mit Vertragstest auf vier Datenbanken; lokales Prüfskript, Workflow sparsam | A-01 bis A-06, A-09, V-01 bis V-03, V-05, B-01 bis B-03, B-06, B-08 | ✅ 28.10.2026 — offen: Touch ID von Hand (A-03), erster Lauf des Workflows auf GitHub |
| [**01 Katalog und Navigator**](timebox-01-katalog-navigator.md) | Arbeitsbildschirm mit Navigator für vier Datenbanken, «alle Datenbanken», Strukturansicht mit Art je Spalte, Schreibschutz und Umgebung, Kontowarnung, Deutsch und Englisch | O-01, O-04, S-01, V-04, N-06, V-10, O-06, N-04 | ✅ 29.10.2026 — V-06 mit TB 07; N-04-Matrix noch nie gelaufen |
| [**02 Datenraster und Filter**](timebox-02-datenraster.md) | Raster mit Blättern über den Schlüssel, NULL zuletzt, Filtern, Suche, Zählen, Fremdschlüssel in beide Richtungen, Zeilen-Detail, Reiter, Kürzel; erste Seite bei 1 Mio. Zeilen unter 20 ms | D-01, D-02, D-03, D-06, N-01, O-03, O-05, O-09 | ✅ 29.10.2026 — «?»-Taste von Hand offen |
| [**03 SQL-Editor und Befehlspalette**](timebox-03-sql-editor.md) | Zerleger je Dialekt, Ausführen mit Zeit- und Zeilengrenze, Abbrechen auf dem Server, SQLite im Arbeitsprozess, Editor mit Vervollständigung, Verlauf, gespeicherte Abfragen, gefährliche Anweisungen nur mit Bestätigung, Befehlspalette ⌘K | Q-01–Q-04, Q-07, Q-09, O-02 | ✅ 29.10.2026 → **Alpha** — Woche im Alltag offen (Hoang) |
| [**04 Bearbeiten mit Vorschau und Rückgängig**](timebox-04-bearbeiten.md) | Änderungen sammeln, Vorschau aus `pretend()` mit Kaskaden, eine Transaktion auf allen vier, Rückgängig mit Konfliktprüfung, Feld-Editor je Art, Kopieren als TSV/Markdown/INSERT, Schreibschutz auf Zeit | D-04, D-05, D-07, D-09, E-05, N-03, V-11 | ✅ 29.10.2026 |
| [**06 Export und Import**](timebox-06-export-import.md) | Export als CSV, JSON oder SQL in Paketen über den Schlüssel; Import von CSV mit Zuordnung und Probelauf, SQL-Datei mit eigenem Zerleger; Statistik nach Importen und auf Knopfdruck; Aufträge im Hintergrund, ohne Worker in der Anfrage mit Grenze | E-01, E-02, E-06, B-07 | ✅ 29.10.2026 — offen: Export einer Abfrage und gefilterter Zeilen |
| [**07 Konten, Rechte, Protokoll**](timebox-07-konten-rechte-protokoll.md) | Policy je Verbindung (`view`, `write`, `manage`, `share`); Teilen; Verwaltung der Konten und Einladungen; Protokoll mit Filtern, Maskierung und Frist; dazu Favoriten und «zuletzt geöffnet» (V-06) | B-04, A-07, V-09, A-05, A-06, V-06 | ✅ 29.10.2026 auf eigenem Zweig — offen: Browser-Durchlauf nach dem Zusammenführen |
| [**09 Server-Verwaltung**](timebox-09-server-verwaltung.md) | Übersicht, Prozesse beenden, Variablen, Konten und Rechte mit Vorschau, Wartung — vier Treiber, Dialekt-Vertrag erweitert, jede Änderung genau ein Eintrag im Protokoll | W-01 bis W-05 | ✅ 29.10.2026 auf eigenem Zweig, vorgezogen — offen: Browser-Durchlauf |
| [**10a Objekte, Beziehungsdiagramm, EXPLAIN**](timebox-10a-objekte-diagramm-explain.md) | Sichten, Routinen, Trigger, Ereignisse, Sequenzen und Typen ansehen, anlegen, ändern, löschen mit Vorschau und Protokoll (Objekt-Dialekt neben dem Vertrag); Beziehungsdiagramm je Schema, geschichtet angeordnet, verschiebbar, als SVG; EXPLAIN als Baum mit Hinweisen auf vier Treibern | S-06, S-07, Q-06 | ✅ 29.10.2026 auf eigenem Zweig — offen: Browser-Durchlauf, Diagramm mit Basalto |

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

**Übernahme aus der Feasibility (erledigt in TB 00):** Die Proben F-1, F-7 und Teile von F-2 sind Vertragstests der Dialekt-Schicht (`tests/Feature/Dialects`); die Probe-Seiten `routes/probe.php`, `resources/js/pages/probe/*` bleiben lokal hinter der Anmeldung, bis Raster und SQL-Editor sie ersetzen — die Raster-Probe ist seit TB 02 entfernt, die Editor-Probe folgt mit TB 03.
