# 00 — Change Request (Projekteingang)

> **PDF der Phase:** [CR-001-phpdbadmin.pdf](CR-001-phpdbadmin.pdf) — CR-001 mit allen Anhängen, bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur cr`).

Die Idee kommt aus dem eigenen Alltag: vier Datenbanken, drei Werkzeuge, keines führt durch die Arbeit. phpdbadmin soll phpMyAdmin und phpPgAdmin in einem Werkzeug ersetzen — intuitiver, sicherer und für MariaDB, MySQL, PostgreSQL und SQLite gleich.

| Dokument | Inhalt | Status |
|---|---|---|
| [Ideensammlung](ideensammlung.md) | W-Fragen: Auslöser, Name, Problem und Lücke, Zielgruppen, Umfang, Abgrenzung, Produktprinzipien P-01 bis P-11, Technik, Betrieb, Geschäftsmodell, Fahrplan, Erfolgskriterien | 🔨 v0.1 — Entwurf mit Annahmen |
| [Marktbild](marktbild.md) | phpMyAdmin, phpPgAdmin, Adminer, pgAdmin, DbGate, CloudBeaver, Jaxon DbAdmin, Desktop-Clients; Befunde B1–B8, Positionierung, Geschäftsmodelle, Quellen | 🔨 v0.1 — **Befund: phpMyAdmin 5.2 nicht für PHP 8.4+, phpPgAdmin ruht, Jaxon DbAdmin neu** |
| [Funktionsraster](funktionsraster.md) | Funktionen der drei Vorbilder je Bereich → v1.0 / v1.1 / v1.2 / Nein | 🔨 v0.1 |
| [CR-001 phpdbadmin](CR-001-phpdbadmin.md) | Formeller Projektauslöser — Problem, Positionierung, Scope, Architektur, Bedienkonzept, **80 Anforderungen A/V/O/D/S/Q/E/W/B/N mit Abnahme**, Alternativen, Stufen, Risiken | ✅ Fassung 3 28.10.2026 — **Weiterführen** entschieden; Feasibility eingearbeitet |
| [CR-001 Dossier (PDF)](CR-001-phpdbadmin.pdf) | CR-001 mit Ideensammlung, Rückfragen, Marktbild, Funktionsraster, Ideen-Backlog | ✅ immer aktuell (Datum und Commit auf der Titelseite) |

`00_sources/` enthält Quellmaterial und wird nicht versioniert — Verzeichnis in [00_sources/README.md](00_sources/README.md).

**Diagramme** (`diagrams/`, aus `werkzeuge/diagramme.py`): Problembild · Marktpositionierung · Scope-Abgrenzung · Systemkontext C4 L1 · Schichten mit Dialekten · Bedienkonzept · Stufen-Fahrplan.
