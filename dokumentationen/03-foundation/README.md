# 03 — Foundation

> **PDF der Phase:** [foundation.pdf](foundation.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 03`).

> **Status:** 🟡 **Foundation v1.0 zum Review (28.10.2026)** — begonnen nach der Verabschiedung der Terms of Reference v1.0 (Hoang: «Ja, Foundation»). Gestaltung v0.1, Solution-Architektur v1.0, Datenmodell v0.1, PRL v1.0, Timebox-Plan v1.0, Wireframes v0.1, ADR-001 bis ADR-011. Offen für Hoang: Review der Wireframes und der Gestaltung (G-01 bis G-08), Entscheid ADR-011 (PHP 8.5).

| Dokument | Inhalt |
|---|---|
| [`gestaltung.md`](gestaltung.md) | **v0.1:** Haltung, Farben hell/dunkel mit gerechneten Kontrasten (aus `werkzeuge/kontraste.py`, alle Paare bestehen), Umgebungen und Rasterzustände, Typografie (Inter, JetBrains Mono), Raster und Formen, 14 Bausteine, Text und Ton, schmale Fenster, Entscheide G-01 bis G-08 |
| [`mockups/`](mockups/README.md) | **Wireframes v0.1:** 14 Bereiche je Desktop und Mobil aus einem Generator (`mockups/mockup-bauen.py`) — Anmeldung, Code, Verbindungsübersicht, Verbindung anlegen, Arbeitsbildschirm, SQL-Vorschau, Detail-Schublade, Struktur, Tabelle ändern, SQL-Editor, Export/Import, Befehlspalette, Konto, Verwaltung |
| [`solution-architecture.md`](solution-architecture.md) | **v1.0:** C4 L2, Plattform (PHP 8.5), Schichten und Verzeichnisse, Anfrageverlauf, Zielverbindungen, Schreibschutz, Katalog, Daten, Struktur, SQL-Editor (Zerleger, Zeilengrenze je Treiber, Abbruch über Merker), Export/Import, Anmeldung, Protokoll und Maskierung, Qualität, Entscheide A-01 bis A-14 |
| [`datenmodell.md`](datenmodell.md) | **v0.1:** App-Datenbank — Grundsätze (kein Passwort, verschlüsselt, Prüfsummen, `dateTime`, `longText`, Fristen), 13 Tabellen, was bewusst fehlt, Löschen und Aufräumen |
| [`prl.md`](prl.md) | **v1.0:** 80 Anforderungen mit Timebox, Grösse und Priorität im Plan — erzeugt aus `werkzeuge/prl-bauen.py`; Must-Anteil Stufe 1 57 %, Stufe 2 45 %, bis v1.0 59 %; Stufe 0 bewusst 90 % (Fundament ohne Rückfallweg) |
| [`timebox-plan.md`](timebox-plan.md) | **v1.0:** dreizehn Timeboxes TB 00–12 mit Anforderungen, Wireframe-Bereichen, Personentagen (≈ 53 PT); TB 00 in fünf Scheiben; Meilensteine Alpha, v1.0, v1.1, v1.2; Übernahme aus der Feasibility; Protokoll-Vorlage |

**Architekturentscheide:** [ADR-001 bis ADR-011](../04-evolutionary-development/README.md#architekturentscheide) — ausformuliert 28.10.2026; ADR-011 (PHP 8.5 als Mindestversion) wartet auf Hoangs Entscheid.

**Diagramme** (`diagrams/`, aus `werkzeuge/diagramme.py`): C4 L2 `architektur-l2` · Ändern mit Vorschau `anfrageverlauf` · ER `er-app-datenbank` · Rollen `use-cases` · Export `export-im-hintergrund` · Timebox-Plan `timebox-plan`; C4 L1 und Schichten in CR-001, Anmeldung im Pre-Project, Anwenden je Treiber und Schreibschutz in der Feasibility.
