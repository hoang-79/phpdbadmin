# 04 — Evolutionary Development

> **PDF der Phase:** [evolutionary-development.pdf](evolutionary-development.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 04`).

📋 **Platzhalter** — Timebox-Protokolle (`timebox-nn-titel.md`) und Architekturentscheide (`adr/ADR-nnn-titel.md`: Kontext → Optionen → Entscheid → Konsequenzen).

## Geplante erste Timebox

| TB | Inhalt | Anforderungen |
|---|---|---|
| **00 Fundament** | Starter-Kit bereinigen: Passwort-Anmeldung, Registrierung, Passwort-Reset und -Bestätigung, Zwei-Faktor, E-Mail-Bestätigung, **Dashboard** und **Profil** entfernen; Anmeldung mit Code per Mail und Passkey; Einladung und Erst-Setup per Konsole; App-Datenbank; Verbindungen mit Test und Verschlüsselung; Dialekt-Schicht mit Vertragstest; MySQL in Herd; Tests auf vier Datenbanken; Workflow sparsam | A-01 bis A-06, A-09, V-01 bis V-03, V-05, B-01 bis B-03, B-06, B-08 |

## Architekturentscheide

Noch keine ausformuliert. Kandidaten aus der [Feasibility § 13](../02-feasibility/feasibility.md#13-folgen-für-anforderungen-und-architektur), auszuformulieren in der Foundation: ADR-001 Dialekt-Schicht · ADR-002 Verbindung je Anfrage · ADR-003 Vorschau und Anwenden je Treiber · ADR-004 Schreibschutz in Schichten · ADR-005 SQL-Editor CodeMirror 6 · ADR-006 Raster TanStack Virtual · ADR-007 Export und Import in Paketen · ADR-008 Anmeldung ohne Passwort · ADR-009 App-Datenbank SQLite · ADR-010 Lizenz MIT.

**Übernahme aus der Feasibility in TB 00:** Probe-Seiten `routes/probe.php`, `resources/js/pages/probe/*` übernehmen oder entfernen; die Proben in `tests/Feature/Feasibility` werden zu Vertragstests der Dialekt-Schicht.
