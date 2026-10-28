# Nguyen Development — phpdbadmin · Projektdokumentation (DSDM/AgilePM)

**phpdbadmin** ist ein Werkzeug im Browser für **MariaDB, MySQL, PostgreSQL und SQLite** — wie phpMyAdmin oder phpPgAdmin, aber eine Oberfläche für alle vier, intuitiver, geführt und sicher. Gebaut auf Laravel 13.

Die Dokumentation folgt dem DSDM-Prozess (Schema wie Fristo und Basalto). Jede Phase hat ein Verzeichnis, der Eingang ist immer ein Change Request.

> **Rahmen (Stand 28.10.2026 — Pre-Project, erste Runde)**
>
> **Produkt:** Datenbank-Verwaltung im Browser für vier Datenbanken mit derselben Bedienung; Leitsatz-Entwurf *«Alle vier Datenbanken. Eine Oberfläche. Nichts passiert ungesehen.»*
> **Vorgaben (Hoang 28.10.2026):** Laravel-Projekt · Starter-Kit **ohne Passwort** (nur Passkey und Code per Mail), **ohne Dashboard**, **ohne Profil** · Doku wie Fristo und Basalto.
> **Entschieden 28.10.2026:** Produkt von Nguyen Development unter **MIT** (R-01, R-40) · **lokal und selbst gehostet**, kein SaaS (R-11) · läuft **neben Basalto**, das bis zur Beta Vorrang hat (R-03) · Name **phpdbadmin** endgültig (R-04).
> **Befund der Recherche:** phpMyAdmin 5.2 ist nicht für PHP 8.4+ freigegeben, phpPgAdmin ruht seit 2023, Adminer ist breit, aber knapp; Jaxon DbAdmin (PHP, 0.35) zielt seit Oktober 2026 in dieselbe Lücke ([Marktbild](00-change_request/marktbild.md)).
> **Technik:** Laravel 13 bringt Verbindungen zur Laufzeit, Introspektion für alle Treiber und DDL mit Vorschau mit — eine Dialekt-Schicht deckt nur die Unterschiede ab ([CR-001 § 3.2](00-change_request/CR-001-phpdbadmin.md#32-architektur)).

```
dokumentationen/
├── 00-change_request/           Projekteingang: Ideensammlung, Marktbild, Funktionsraster, CR-001
│   └── 00_sources/              Quellmaterial (Screenshots, Exporte) – nicht versioniert
├── 01-pre-project/              Ausgangslage und Rückfragen (Interview), Ideen-Backlog, Terms of Reference
├── 02-feasibility/              Machbarkeit mit Proben auf vier Datenbanken (F-1 bis F-10), Lösungsoptionen, Risiken
├── 03-foundation/               Gestaltung, PRL, Solution-Architektur, Datenmodell, Timebox-Plan
│   └── mockups/                 Wireframes Desktop und Mobil
├── 04-evolutionary-development/ Timebox-Protokolle, ADRs
│   └── adr/                     Architekturentscheide (ADR-nnn-titel.md)
├── 05-deployment/               Umgebungen, Pipeline, Betrieb, Testmanagement, Release-Notes
├── 06-post-project/             Benefits Review, Lessons Learned, Folge-CRs
└── werkzeuge/                   diagramme.py (SVG-Generator), pdf-bauen.py + pdf.css (ein PDF je Phase), hook-einrichten.sh
```

Jede Phase hat zusätzlich einen Ordner `diagrams/`.

## Dokumentationsregeln (verbindlich)

1. **Grafik vor Text:** Sachverhalte primär als Diagramm (SVG), darunter 3–5 Sätze Erklärung. Kein Kapitel ohne Diagramm, wenn visualisierbar.
2. **SVG aus dem Generator:** Diagramme als `.svg` phasen-lokal in `diagrams/`, erzeugt mit `python3 dokumentationen/werkzeuge/diagramme.py` (nur Standardbibliothek). Geändert wird im Generator, nie in der SVG-Datei; jedes Diagramm wird nach dem Erzeugen angesehen (`rsvg-convert` → PNG), bevor es ins Dokument kommt. Farben vorläufig Schiefer und Petrol, bis die Marke in der Foundation steht.
3. **Immer aktuell:** Die Doku wird **bei jedem Commit** nachgeführt. Änderungen an Modell, Ablauf, Oberfläche oder Architektur aktualisieren die betroffenen Dokumente und Diagramme im selben Commit. Veraltete Doku = Defect. Nach erledigter Arbeit wird committet.
4. **Pflichtdiagramme:** C4 L1 und L2 · Use Cases je Rolle · ER der App-Datenbank (nach jeder Migration) · Sequenzen für Anmeldung, Schreiben mit SQL-Vorschau und Export im Hintergrund · Schichten mit Dialekten.
5. **ADRs:** `04-evolutionary-development/adr/ADR-nnn-titel.md` (Kontext → Optionen → Entscheid → Konsequenzen).
6. **Sprache:** Deutsch in Schweizer Schreibweise (kein ß) mit echten Umlauten, auch in Code-Kommentaren und Commits. Bezeichner in Code und App-Datenbank englisch (Annahme R-35).
7. **Quellen:** Verzeichnis in [00_sources](00-change_request/00_sources/README.md), Webquellen mit Abrufdatum und Prüfart im [Marktbild](00-change_request/marktbild.md#6-quellen-abgerufen-28102026). Kein Code aus Arbeitgeber-Projekten; aus Basalto und Fristo (eigene Projekte) darf übernommen werden; Code von phpMyAdmin, phpPgAdmin und Adminer wird nicht übernommen.
8. **Ein PDF je Phase, immer aktuell:** Jede Phase hat ein Dossier mit ihrem Leitdokument zuerst und den übrigen Dokumenten als Anhänge — Titelseite mit Datum und Commit, Inhalt, Seitenzahlen, Diagramme quer. `python3 dokumentationen/werkzeuge/pdf-bauen.py` baut alle, `--nur 01` eines, `--pruefen` meldet veraltete PDFs; der Pre-Commit-Hook (`sh dokumentationen/werkzeuge/hook-einrichten.sh`) lässt keinen Doku-Commit mit veraltetem PDF durch. Voraussetzungen: `pandoc`, Google Chrome.

| Phase | PDF | Leitdokument und Anhänge |
|---|---|---|
| 00 | [CR-001-phpdbadmin.pdf](00-change_request/CR-001-phpdbadmin.pdf) | CR-001 Fassung 2 + Ideensammlung, Rückfragen, Marktbild, Funktionsraster, Ideen-Backlog |
| 01 | [terms-of-reference.pdf](01-pre-project/terms-of-reference.pdf) | Terms of Reference v0.1 + Übersicht, Rückfragen, Ideen-Backlog |
| 02 | [feasibility.pdf](02-feasibility/feasibility.pdf) | Prüfpunkte F-1 bis F-10 (geplant) |
| 03 | [foundation.pdf](03-foundation/foundation.pdf) | Platzhalter |
| 04 | [evolutionary-development.pdf](04-evolutionary-development/evolutionary-development.pdf) | geplante TB 00 |
| 05 | [deployment.pdf](05-deployment/deployment.pdf) | Zielbild |
| 06 | [post-project.pdf](06-post-project/post-project.pdf) | Platzhalter |

## Umgebungen (Zielbild, Details in [05-deployment](05-deployment/README.md))

| Stufe | Host | Bemerkung |
|---|---|---|
| Dev | `https://phpdbadmin.test` | Laravel Herd (macOS), HTTPS aktiv — Passkeys brauchen einen sicheren Ursprung |
| Demo | offen | SQLite-Beispieldatenbanken, nach v1.0 (R-30) |
| Produktion | beim Anwender | selbst gehostet neben den eigenen Datenbanken (entschieden, R-11) |

- **Stack (installiert):** Laravel 13.39 (PHP 8.5) · Inertia v3.5 · Vue 3.5 · TypeScript · Tailwind 4 · Vite (vite-plus 1.4) · Wayfinder · Fortify 1.41 (mit `laravel/passkeys`) · PHPUnit 12 · Larastan · Pint · Laravel Boost.
- **Lokale Datenbanken (Herd):** MariaDB 10.11.6 :3306 · PostgreSQL 18 :5432 · SQLite · MySQL 8.4 noch zu installieren (R-33).
- **Repo:** lokal `~/Development/phpdbadmin`, Branch `main`; GitHub-Remote noch nicht angelegt (R-07).

## Status

| Phase | Dokument | Status |
|---|---|---|
| 00 | [Ideensammlung](00-change_request/ideensammlung.md) | 🔨 v0.1 — W-Fragen, Prinzipien P-01 bis P-11 |
| 00 | [Marktbild](00-change_request/marktbild.md) | 🔨 v0.1 — acht Befunde, Positionierung A + C, Quellen mit Prüfart |
| 00 | [Funktionsraster](00-change_request/funktionsraster.md) | 🔨 v0.1 — drei Vorbilder → v1.0 / v1.1 / v1.2 / Nein |
| 00 | [CR-001 phpdbadmin](00-change_request/CR-001-phpdbadmin.md) · [PDF-Dossier](00-change_request/CR-001-phpdbadmin.pdf) | 🟡 Fassung 2 (Entwurf) — 77 Anforderungen, Kernfragen entschieden; Entscheid «Weiterführen» steht aus |
| 01 | [Ausgangslage und Rückfragen](01-pre-project/ausgangslage-und-rueckfragen.md) | 🔨 erste Runde — R-01, R-03, R-04, R-11, R-40 entschieden; übrige mit Annahmen |
| 01 | [Ideen-Backlog](01-pre-project/ideen-backlog.md) | 🔨 erste Sammlung |
| 01 | [Terms of Reference](01-pre-project/terms-of-reference.md) · [PDF](01-pre-project/terms-of-reference.pdf) | 🟡 Entwurf v0.1 |
| 02 | [Feasibility](02-feasibility/README.md) | 📋 geplant — F-1 bis F-10 |
| 03 | [Foundation](03-foundation/README.md) | 📋 Platzhalter |
| 04 | [Evolutionary Development](04-evolutionary-development/README.md) | 📋 TB 00 Fundament geplant (Starter-Kit bereinigen, Anmeldung, Verbindungen) |
| 05 | [Deployment](05-deployment/README.md) | 📋 Zielbild |
| 06 | [Post-Project](06-post-project/README.md) | 📋 Platzhalter |

Legende: ✅ abgeschlossen · 🔨 in Arbeit · 🟡 vorbereitet · 📋 Platzhalter
