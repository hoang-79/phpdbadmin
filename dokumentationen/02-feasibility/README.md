# 02 — Feasibility

> **PDF der Phase:** [feasibility.pdf](feasibility.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 02`).

✅ **Abgeschlossen am 28.10.2026** — gestartet auf Hoangs «Feasibility starten». Jeder Prüfpunkt ist mit einer Probe im Repo belegt, die gegen **MySQL 8.4.11, MariaDB 10.11.6, PostgreSQL 18 und SQLite 3.45** läuft (`tests/Feature/Feasibility`), oder mit einer Recherche mit Fundstellen. **6 bestanden, 4 bedingt, 0 nicht bestanden — Empfehlung: Weiterführen.**

> **Nachtrag TB 00 (28.10.2026):** Die Proben F-1 (`CatalogProbeTest`), F-7 (`SecurityProbeTest`) und die Teile Zeitlimit, Zeitzone und Verbindungsfehler von F-2 sind zu **Vertragstests der Dialekt-Schicht** geworden (`tests/Feature/Dialects/DialectContractTest.php`, `tests/Feature/Connections/ConnectionTesterTest.php`); die Probe-Dateien sind entfernt (Stand der Feasibility: Commit `13a658a`). Proben bleiben, bis ihre Timebox kommt: Abbrechen (F-2, SQL-Editor), DDL-Vorschau (F-5), Raster und Export mit 1 Mio. Zeilen (F-3, F-8).

| Dokument | Inhalt | Status |
|---|---|---|
| [Feasibility-Bericht](feasibility.md) | Prüfpunkte F-1 bis F-10 mit Frage, Nachweis, Befunden, Ergebnis und Folgen; neue und geänderte Anforderungen; ADR-Kandidaten; Empfehlung | ✅ v1.0 |
| [Sicherheit und Bedrohungen](sicherheit-und-bedrohungen.md) | Schutzgüter, 18 Bedrohungen mit Gegenmittel und Beleg, Restrisiken | 🔨 v0.1 |
| [Name und Marke](name-und-marke.md) | Markenlage phpMyAdmin und phpdbadmin, Namensräume, Domains, Auflagen | ✅ Recherchebefund |

| Nr. | Prüfpunkt | Ergebnis | Probe |
|---|---|---|---|
| F-1 | Katalog | ✅ bestanden | `CatalogProbeTest` |
| F-2 | Verbindungen | 🟡 bedingt | `ConnectionProbeTest` |
| F-3 | Raster, 1 Mio. Zeilen | ✅ bestanden | `LargeTableProbeTest` (`PROBE_GROSS=1`), `/probe/raster` |
| F-4 | SQL-Editor | ✅ bestanden (Sichtprüfung offen) | `/probe/editor`, `editor-completion-probe.mjs` |
| F-5 | DDL mit Vorschau | 🟡 bedingt | `DdlPreviewProbeTest` |
| F-6 | Anmeldung | ✅ bestanden | Quellcode Fortify, Vorlage Basalto |
| F-7 | Sicherheit | 🟡 bedingt | `SecurityProbeTest` |
| F-8 | Export und Import | ✅ bestanden | `LargeTableProbeTest` |
| F-9 | Lizenzen | ✅ bestanden | `composer licenses`, npm |
| F-10 | Name absichern | 🟡 bedingt | [Name und Marke](name-und-marke.md) |

**Proben wiederholen:** `php vendor/bin/phpunit --group feasibility` (Herd-Dienste müssen laufen; ein nicht erreichbarer Server wird ausdrücklich übersprungen) · schwer: `PROBE_GROSS=1 php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Feasibility/LargeTableProbeTest.php` · Editor: `node tests/Feature/Feasibility/editor-completion-probe.mjs`. Befunde landen in `storage/framework/testing/feasibility/*.json`.

**Diagramme** (`diagrams/`, aus `werkzeuge/diagramme.py`): Prüfmatrix · Messwerte mit einer Million Zeilen · Anwenden je Treiber · Schreibschutz in Schichten.
