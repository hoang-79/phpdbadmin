# Ideensammlung — phpdbadmin

**Stand:** 28.10.2026 · v0.1 — Entwurf mit Annahmen, lebendes Dokument der Ideenphase. Offene Punkte tragen eine Rückfrage-Nummer aus [Ausgangslage und Rückfragen](../01-pre-project/ausgangslage-und-rueckfragen.md); bis zur Antwort gilt die Annahme dort.

## 1. Ausgangslage

### 1.1 Auslöser

Hoang am 28.10.2026: «Ein Admin-Tool für verschiedene Datenbanken — MariaDB, MySQL, PostgreSQL und SQLite. Wie phpMyAdmin oder phpPgAdmin, aber intuitiver und benutzerfreundlicher, und es soll verschiedene Plattformen bedienen können.» Dazu drei feste Vorgaben für das Laravel-Starter-Kit: **kein Passwort** (nur Passkey und Code per Mail), **kein Dashboard**, **kein Profil**. Vorgehen und Dokumentation wie bei Fristo und Basalto.

Ausgangspunkt im Repo ist ein frisches Laravel-13-Starter-Kit (Vue) mit Laravel Boost (Commits `a7a2127` bis `fa95ab3`). Unter `~/Development` liegen Kopien von phpMyAdmin 5.2.2 und phpPgAdmin 7.13.0 — sie dienen als Anschauung für den Funktionsumfang; ihr Code wird nicht übernommen (GPL, und phpdbadmin baut seine Oberfläche ohnehin neu).

### 1.2 Name

**phpdbadmin** ist der Name — entschieden von Hoang am 28.10.2026 (R-04), nach der Prüfung unten und gegen meinen Vorschlag einer Namensfindung. Er sagt sofort, was gemeint ist: «wie phpMyAdmin, aber für alle Datenbanken», und wird so auch gesucht.

| Prüfung | Befund (28.10.2026) |
|---|---|
| Domain `phpdbadmin.ch` | frei (SWITCH-Whois) |
| Domain `phpdbadmin.com` | belegt seit 30.08.2025 (Inhaber nicht geprüft) |
| Gleichnamige Software | keine gefunden (Websuche); ähnlich: «PHP Data Admin» (SourceForge, seit 2015 ruhend) |
| Sprechtest | «pe-ha-pe-de-be-admin» — sieben Silben, am Telefon nur buchstabiert verständlich (Lehre aus der Namensfindung von Fristo: erst sprechen, dann suchen) |
| Verwechslung | sehr nahe an «phpMyAdmin» — hilft beim Finden, kann aber Markenrechte des phpMyAdmin-Projekts berühren (zu prüfen) |
| Aussage | nennt die Technik (PHP), nicht den Nutzen; Kundinnen und Kunden interessiert PHP nicht |

Mein Vorschlag war, den Namen nur als Arbeitstitel zu führen und vor dem ersten öffentlichen Auftritt eine Namensfindung zu machen. **Entscheid 28.10.2026: phpdbadmin ist der Name.** Folgen: `phpdbadmin.ch` sichern (Hoang); vor dem ersten öffentlichen Auftritt eine Markenrecherche (IGE, EUIPO) zur Nähe zu «phpMyAdmin» und ein eigenständiger Auftritt (Logo, Farben), der nicht wie phpMyAdmin aussieht (Prüfpunkt F-10).

### 1.3 Bezug zu den anderen Vorhaben

Basalto (CMS, läuft auf SQLite, MariaDB, MySQL und PostgreSQL), Fristo (PostgreSQL) und die Kundenprojekte brauchen alle einen Blick in ihre Datenbanken. Heute geschieht das mit wechselnden Werkzeugen. phpdbadmin wäre das gemeinsame Werkzeug — und zugleich das Projekt, in dem das in Basalto gesammelte Wissen über Treiberunterschiede (NULL-Sortierung, JSON-Schlüsselordnung in MySQL, Collations, `TEXT`-Grenze 64 KB, `timestamp`-Fallen in MariaDB 10.6) zum Produkt wird.

## 2. Warum — Problem und Lücke

![Problembild: heute und mit phpdbadmin](diagrams/problembild-ist-soll.svg)

### 2.1 Das Problem

1. **Ein Werkzeug je Datenbank.** phpMyAdmin kann nur MySQL und MariaDB, phpPgAdmin nur PostgreSQL, für SQLite braucht es ein drittes. Wer wie Hoang alle vier nutzt, wechselt Oberfläche, Begriffe und Gewohnheiten.
2. **Bedienung aus einer anderen Zeit.** Ein Formular je Datensatz, viele Reiter, Bestätigungen ohne Inhalt («Wirklich ausführen?»). Die Werkzeuge zeigen Tabellen, aber sie führen nicht durch die Arbeit.
3. **Ändern ohne Netz.** Ein Klick schreibt sofort; es gibt keine Vorschau des SQL, kein Sammeln von Änderungen, kein Rückgängig und kein Protokoll. Die Produktion sieht aus wie die lokale Datenbank.
4. **Anmeldung mit Datenbank-Passwörtern.** phpMyAdmin und Adminer melden direkt mit Datenbank-Zugangsdaten an; öffentlich erreichbare Installationen sind ein bekanntes Angriffsziel.
5. **Technischer Stillstand.** phpMyAdmin 5.2.3 (Oktober 2025) läuft offiziell nur bis PHP 8.3, Version 6 ist seit Jahren in Entwicklung; phpPgAdmin hat seit Dezember 2023 keinen Commit mehr und eine offene XSS-Meldung von 2026 ([Marktbild § 1](marktbild.md#1-übersicht)). Herd und neue Server laufen mit PHP 8.4 und 8.5.

### 2.2 Die Lücke (These, im Marktbild geprüft)

Ein Werkzeug **im Browser**, das **alle vier Datenbanken mit derselben Bedienung** abdeckt, **geführt** arbeitet (Vorschau, Sammeln, Schutz), **auf dem Laravel-Stack** läuft (Herd, jeder PHP-Server) und **eigene Konten ohne Passwort** hat. Adminer deckt die Breite ab, aber nicht die Führung; DbGate und CloudBeaver haben moderne Oberflächen, aber nicht PHP; Jaxon DbAdmin (seit Oktober 2026 sichtbar) zielt in dieselbe Richtung, steht aber bei Version 0.35 auf dem Kern von Adminer mit Bootstrap-Oberfläche ([Marktbild § 2](marktbild.md#2-was-der-markt-zeigt)).

### 2.3 Warum wir

- Laravel 13 bringt die Mehrdatenbank-Fähigkeit schon mit: Verbindungen zur Laufzeit (`DB::build()`, `DB::connectUsing()`), Introspektion für alle Treiber (`Schema::getSchemas()`, `getTables()`, `getViews()`, `getTypes()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`), DDL-Erzeugung mit Vorschau (`Blueprint::toSql()`, `Connection::pretend()`) — geprüft im Quellcode von Laravel 13.39 am 28.10.2026.
- Die passwortlose Anmeldung mit Code und Passkey ist in Basalto gebaut und erprobt (eigenes Projekt, darf übernommen werden).
- Die Lektionen aus Basalto und Fristo zu den Unterschieden der vier Treiber sind genau das Wissen, das ein Datenbank-Werkzeug braucht.

## 3. Für wen — Zielgruppen und Rollen

| Gruppe | Situation | Was sie braucht |
|---|---|---|
| **Laravel- und PHP-Entwickler** (zuerst, Annahme R-10) | lokal in Herd, MariaDB und PostgreSQL nebeneinander, SQLite in Tests | schnell nachsehen, filtern, eine Zeile korrigieren, Struktur prüfen, SQL ausprobieren |
| **Betrieb in kleinen Teams** | phpMyAdmin auf dem Server, mehrere Personen | Konten statt geteilter DB-Passwörter, Rechte je Verbindung, Protokoll, Schreibschutz für die Produktion |
| **Fachleute mit Datenpflege** | pflegen Stammdaten, exportieren für Excel | geführte Bearbeitung, verständliche Fehlermeldungen, Export ohne SQL |
| **Nguyen Development** | Basalto, Fristo, Kundenprojekte | ein Werkzeug für alle Projekte; Schaufenster für Laravel-Handwerk |

**Rollen in der Anwendung (Vorschlag):** Administrator (Konten, Verbindungen, Einstellungen) · Mitglied mit Recht je Verbindung: *lesen*, *schreiben* oder *verwalten* (Struktur, Benutzer und Rechte des Servers).

## 4. Was — Funktionsumfang

![Abgrenzung des Funktionsumfangs](diagrams/scope-abgrenzung.svg)

Kern bis v1.0 sind Anmelden, Verbinden, Lesen, Bearbeiten und Austauschen; danach folgen die Server-Verwaltung (v1.1) sowie Komfort- und Team-Funktionen (v1.2). Die vollständige Einordnung je Funktion mit dem Vergleich zu phpMyAdmin, phpPgAdmin und Adminer steht im [Funktionsraster](funktionsraster.md), die Anforderungen mit Abnahme in [CR-001 § 4](CR-001-phpdbadmin.md#4-anforderungen).

## 5. Was nicht — Abgrenzung (Vorschlag)

- **Weitere Datenbanken** (SQL Server, Oracle, NoSQL wie MongoDB und Redis) — nicht vor v1.2; die Dialekt-Schicht hält die Tür offen (R-14).
- **SaaS mit fremden Zugangsdaten** — phpdbadmin wird lokal oder selbst gehostet betrieben; Zugangsdaten fremder Datenbanken auf einem eigenen Server zu halten, wäre ein anderes Produkt mit anderem Risiko (R-11).
- **Desktop-App**, **Berichte und BI** (Diagramme aus Daten), **Replikation und Cluster einrichten**, **Hosting-Panel** (Konten, Domains wie cPanel).
- **Passwort-Anmeldung, Dashboard, Profilseite** — ausdrücklich entfernt (Vorgabe).

## 6. Wie — Produktprinzipien (Vorschlag)

Was «intuitiver und benutzerfreundlicher» heisst, legen diese Prinzipien fest. Jedes ist im [Bedienkonzept](diagrams/bedienkonzept.svg) sichtbar und in [CR-001 § 4](CR-001-phpdbadmin.md#4-anforderungen) mit einer Abnahme verknüpft.

![Bedienkonzept](diagrams/bedienkonzept.svg)

| Nr. | Prinzip | Was es bedeutet |
|---|---|---|
| P-01 | **Eine Sprache für vier Datenbanken** | gleiche Begriffe und Bildschirme; Unterschiede nur, wo sie fachlich zählen (Schemas in PostgreSQL, Engines in MySQL), dann mit Erklärung |
| P-02 | **Nichts passiert ungesehen** | jede schreibende Aktion zeigt ihr SQL vor der Ausführung; Änderungen werden gesammelt und einmal angewendet — in einer Transaktion, wo der Treiber es kann (DDL in MySQL und MariaDB nicht: das wird ehrlich angezeigt) |
| P-03 | **Direkt bearbeiten statt Formular** | Raster wie eine Tabellenkalkulation; lange Inhalte (JSON, Text, Binärdaten) in einer Seitenschublade, inline und ohne Fenster in der Schublade |
| P-04 | **Gefahr sichtbar machen, nicht verbieten** | Umgebungsfarbe je Verbindung, Schreibschutz-Schalter, Namenseingabe für DROP und TRUNCATE, betroffene Zeilen vor UPDATE und DELETE zählen, Rückgängig für Datenänderungen |
| P-05 | **Tastatur zuerst** | Befehlspalette ⌘K, Ausführen mit ⌘↵, Pfeiltasten im Raster |
| P-06 | **Schnell auch bei grossen Tabellen** | nie unbegrenzt lesen; Blättern über den Schlüssel, geschätzte Zeilenzahl, Abbrechen langer Abfragen, Export im Hintergrund |
| P-07 | **Zustände erklären** | leere Tabelle, Fehler, Laden — jeweils mit Ursache in Klartext und dem nächsten Schritt |
| P-08 | **Lernen nebenbei** | das gezeigte SQL ist kopierbar und lässt sich im Editor öffnen |
| P-09 | **Sicher ab Werk** | kein Passwort, verschlüsselte Zugangsdaten, erlaubte Hosts, Protokoll, CSP |
| P-10 | **Keine Scrollbalken, wo vermeidbar** | Regel von Hoang (15.10.2026); das Datenraster ist die begründete Ausnahme für waagrechtes Scrollen, mit fixierter erster Spalte und Verlaufskante (R-25) |
| P-11 | **Deutsch und Englisch, Sie-Form** | Oberfläche zweisprachig ab v1.0 |

## 7. Womit — Technik (Vorschlag)

| Baustein | Vorschlag | Stand |
|---|---|---|
| Rahmen | Laravel 13.39 (PHP 8.5), Inertia v3.5, Vue 3, TypeScript, Tailwind 4, Vite (vite-plus), Wayfinder | installiert |
| Anmeldung | Fortify 1.41 nur für Passkeys (enthält `laravel/passkeys`), eigener Code-Login per Mail | installiert, umzubauen in Stufe 0 |
| Zielverbindungen | Konfiguration aus der App-Datenbank, Verbindung je Anfrage mit `DB::build()`, Grenzen je Treiber (Zeitlimit, nur lesen) | Prüfpunkt Feasibility |
| Katalog | Laravel-Schema-Introspektion, ergänzt durch eine **Dialekt-Schicht** je Treiber für Prozesse, Rechte, Routinen, EXPLAIN, Server-Werte | Prüfpunkt Feasibility |
| Struktur ändern | `Blueprint` + `toSql()` bzw. `pretend()` für die SQL-Vorschau; SQLite-Tabellenumbau wie Laravel | Prüfpunkt Feasibility |
| SQL-Editor | CodeMirror 6 mit SQL-Dialekten (MIT) | probeweise installiert 28.10.2026 (Freigabe Hoang), F-4 bestanden |
| Datenraster | eigenes Raster mit `@tanstack/vue-virtual` (MIT), Blättern über den Schlüssel | probeweise installiert, F-3 bestanden |
| App-Datenbank | SQLite als Vorgabe (Konten, Passkeys, Verbindungen, Verlauf, Protokoll) | Annahme R-31 |
| Hintergrund | Queue (`database`) für Export und Import | vorhanden |
| Tests | PHPUnit 12 gegen MySQL 8.4, MariaDB 10.11, PostgreSQL 18 und SQLite | MySQL 8.4.11 in Herd seit 28.10.2026; Feasibility-Proben laufen auf allen vier |

Die Schichten im Detail: [Architektur-Diagramm in CR-001 § 3.2](CR-001-phpdbadmin.md#32-architektur).

## 8. Wo — Betrieb und Umgebungen

| Stufe | Ort | Bemerkung |
|---|---|---|
| Dev | `https://phpdbadmin.test` | Laravel Herd (macOS), HTTPS aktiv — Passkeys brauchen einen sicheren Ursprung; MySQL 8.4.11 :3307, MariaDB 10.11.6 :3306, PostgreSQL 18 :5432 |
| Testing / Demo | offen | Demo mit mitgelieferten SQLite-Beispieldatenbanken denkbar (R-30) |
| Produktion | beim Anwender | selbst gehostet neben den eigenen Datenbanken; kein SaaS (entschieden 28.10.2026, R-11) |

Ein Hinweis zur Cloud: Ein Datenbank-Werkzeug muss die Datenbanken erreichen, und die liegen meist in internen Netzen. Laravel Cloud eignet sich deshalb höchstens für eine Demo, nicht als Betriebsort des Produkts.

## 9. Wieviel — Geschäftsmodell (offen)

Der Markt ist von kostenloser Software geprägt (phpMyAdmin, Adminer, pgAdmin), daneben Open Core (DbGate, Beekeeper Studio, CloudBeaver) und bezahlte Desktop-Werkzeuge (TablePlus, DataGrip) — [Marktbild § 4](marktbild.md#4-geschäftsmodelle-im-markt). Drei Wege:

| Option | Beschreibung | Einschätzung |
|---|---|---|
| A — Open Source (MIT) | frei, Schaufenster für Nguyen Development | passt zum Markt; kein direkter Erlös |
| B — Open Core | Kern frei, Team-Funktionen (Rollen, geteilte Verbindungen, Protokoll-Export, SSO) bezahlt | Erlös möglich, aber Abgrenzung pflegen |
| C — kommerziell wie Basalto | Lizenz je Installation | schwer gegen kostenlose Platzhirsche |

**Entschieden 28.10.2026: A — Open Source unter MIT**, Träger Nguyen Development (R-01, R-40). Team-Funktionen lassen sich später als B anbieten. Folge: Abhängigkeiten müssen MIT-verträglich sein; Code aus GPL-Projekten (phpMyAdmin, phpPgAdmin) kommt ohnehin nicht hinein.

## 10. Wann — Fahrplan (Grobvorschlag)

![Stufen und Meilensteine](diagrams/stufen-fahrplan.svg)

Rund 40–50 Personentage bis v1.2, Schätzung vor der Feasibility. **Entschieden 28.10.2026 (R-03):** phpdbadmin läuft in kleinen Timeboxes neben Basalto, das bis zur Beta (Anfang 2027) Vorrang behält; Stufen 0–1 bis zur Alpha zuerst.

## 11. Wer — Rollen

Hoang Nguyen als Business Sponsor, Visionary, Projektleitung und Technical Coordinator in Personalunion; Claude Code als Pair. Business Ambassador: Hoang selbst als erster Nutzer, später ein Laravel-Entwickler aus dem Umfeld als Gegenleser (R-02).

## 12. Woran gemessen — Erfolgskriterien (Vorschlag)

- Hoang ersetzt phpMyAdmin, phpPgAdmin und den Desktop-Client im eigenen Alltag ab v1.0.
- Häufige Aufgaben (Tabelle öffnen, filtern, Zeile ändern, exportieren) in höchstens drei Klicks oder zwei Tastenkürzeln.
- Dieselben Abläufe sind auf vier Datenbanken automatisch getestet (MySQL 8.4, MariaDB 10.11, PostgreSQL 18, SQLite).
- Die erste Seite einer Tabelle mit einer Million Zeilen erscheint lokal in unter einer Sekunde.
- Eine White-Box-Sicherheitsprüfung nach OWASP vor v1.0 ohne hohen Befund.
- Kernbildschirme ohne Befund nach WCAG 2.2 AA.

## 13. Offene Entscheide

Alle offenen Punkte stehen mit Annahme im [Rückfragen-Katalog](../01-pre-project/ausgangslage-und-rueckfragen.md); entschieden sind am 28.10.2026 Träger und Lizenz (R-01, R-40), Betriebsmodell (R-11), Vorrang (R-03) und Name (R-04).
