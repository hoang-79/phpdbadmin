# 03 — Foundation

> **PDF der Phase:** [foundation.pdf](foundation.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 03`).

> **Status:** ✅ **Foundation v1.0 abgeschlossen (28.10.2026)** — begonnen nach der Verabschiedung der Terms of Reference v1.0 (Hoang: «Ja, Foundation»). Review am selben Tag interaktiv entschieden: Schreibschutz auf Zeit als **V-11**, Raster **endlos scrollen**, **PHP 8.5** (ADR-011), Wireframe-Fragen F-2 bis F-6 wie empfohlen ([Rückfragen § 5](../01-pre-project/ausgangslage-und-rueckfragen.md#5-entscheide-aus-dem-review-der-foundation-vom-28102026)). Die Gestaltung ist mit TB 00 im Browser umgesetzt und gegen die Wireframes 1–4 und 13 bei 1440 und 375 px, hell und dunkel, durchgegangen ([Protokoll TB 00](../04-evolutionary-development/timebox-00-fundament.md#browser-durchlauf)); offen bleibt Hoangs eigene Sichtprüfung (G-01 bis G-08).

| Dokument | Inhalt |
|---|---|
| [`gestaltung.md`](gestaltung.md) | **v0.1:** Haltung, Farben hell/dunkel mit gerechneten Kontrasten (aus `werkzeuge/kontraste.py`, alle Paare bestehen), Umgebungen und Rasterzustände, Typografie (Inter, JetBrains Mono), Raster und Formen, 14 Bausteine, Text und Ton, schmale Fenster, Entscheide G-01 bis G-08 |
| [`mockups/`](mockups/README.md) | **Wireframes v0.1:** 14 Bereiche je Desktop und Mobil aus einem Generator (`mockups/mockup-bauen.py`) — Anmeldung, Code, Verbindungsübersicht, Verbindung anlegen, Arbeitsbildschirm, SQL-Vorschau, Detail-Schublade, Struktur, Tabelle ändern, SQL-Editor, Export/Import, Befehlspalette, Konto, Verwaltung |
| [`solution-architecture.md`](solution-architecture.md) | **v1.0:** C4 L2, Plattform (PHP 8.5), Schichten und Verzeichnisse, Anfrageverlauf, Zielverbindungen, Schreibschutz, Katalog, Daten, Struktur, SQL-Editor (Zerleger, Zeilengrenze je Treiber, Abbruch über Merker), Export/Import, Anmeldung, Protokoll und Maskierung, Qualität, Entscheide A-01 bis A-14 |
| [`datenmodell.md`](datenmodell.md) | **v0.1:** App-Datenbank — Grundsätze (kein Passwort, verschlüsselt, Prüfsummen, `dateTime`, `longText`, Fristen), 13 Tabellen, was bewusst fehlt, Löschen und Aufräumen |
| [`prl.md`](prl.md) | **v1.0:** 81 Anforderungen mit Timebox, Grösse und Priorität im Plan — erzeugt aus `werkzeuge/prl-bauen.py`; Must-Anteil Stufe 1 57 %, Stufe 2 44 %, bis v1.0 58 %; Stufe 0 bewusst 90 % (Fundament ohne Rückfallweg) |
| [`timebox-plan.md`](timebox-plan.md) | **v1.0:** dreizehn Timeboxes TB 00–12 mit Anforderungen, Wireframe-Bereichen, Personentagen (≈ 53 PT); TB 00 in fünf Scheiben; Meilensteine Alpha, v1.0, v1.1, v1.2; Übernahme aus der Feasibility; Protokoll-Vorlage |

**Architekturentscheide:** [ADR-001 bis ADR-011](../04-evolutionary-development/README.md#architekturentscheide) — ausformuliert und angenommen 28.10.2026 (ADR-011 PHP 8.5 von Hoang entschieden).

**Diagramme** (`diagrams/`, aus `werkzeuge/diagramme.py`): C4 L2 `architektur-l2` · Ändern mit Vorschau `anfrageverlauf` · ER `er-app-datenbank` · Rollen `use-cases` · Export `export-im-hintergrund` · Timebox-Plan `timebox-plan`; C4 L1 und Schichten in CR-001, Anmeldung im Pre-Project, Anwenden je Treiber und Schreibschutz in der Feasibility.
