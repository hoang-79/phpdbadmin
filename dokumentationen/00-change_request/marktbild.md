# Marktbild — Datenbank-Werkzeuge 2026

**Stand:** 28.10.2026 · v0.1 — aus einer Recherche am selben Tag. Jede Angabe nennt ihre Quelle und wie sie geprüft ist: **geöffnet** (Seite selbst gelesen) oder **Suche** (aus der Zusammenfassung einer Websuche, Seite nicht geöffnet — vor Verwendung in Texten für draussen nachprüfen).

## 1. Übersicht

| Werkzeug | Art | Datenbanken | Technik | Lizenz | Stand | Prüfung |
|---|---|---|---|---|---|---|
| **phpMyAdmin** | Web | MySQL, MariaDB | PHP; 5.2: PHP 7.2 bis unter 8.4 | GPL-2.0 | 5.2.3 vom 08.10.2025; 6.0 «in Entwicklung» (täglicher Snapshot 27.10.2026, PHP 8.2 bis unter 8.4) | geöffnet [1] |
| **phpPgAdmin** | Web | PostgreSQL | PHP (ADOdb) | GPL-2.0 | 7.13.0; letzter Commit Dezember 2023 (Packagist); offene Meldung «RXSS» vom 11.03.2026; gepflegter Fork 7.14.8-mod | Suche [2] |
| **Adminer** | Web, eine Datei | MySQL, MariaDB, PostgreSQL, SQLite, MS SQL, Oracle, CockroachDB; weitere per Plugin | PHP | Apache-2.0 oder GPL-2.0 (zu bestätigen) | 6.2.0 vom 23.10.2026; 6.0 brachte im August 2026 Anmeldung ohne Passwort | geöffnet [3] |
| **pgAdmin 4** | Web und Desktop | PostgreSQL | Python | PostgreSQL-Lizenz | 9.18 vom 17.09.2026; monatliche Releases, je 4–8 behobene CVEs; KI-Funktionen seit 9.13 | Suche [4] |
| **DbGate** | Web (Docker, npm) und Desktop | SQL und NoSQL, u. a. alle vier | Node.js | GPL-3.0 (Community) + Premium | 7.3.0 vom 15.09.2026; Community laut Bericht nur Einzelbenutzer | Suche [5] |
| **CloudBeaver** | Web | sehr viele (DBeaver-Kern) | Java | Community + Enterprise | Version nicht geprüft | Suche [6] |
| **Jaxon DbAdmin** | Web, Paket für Laravel, Symfony, Slim | MySQL, MariaDB, PostgreSQL, SQLite | PHP; Funktionen aus Adminer, Bootstrap 5, CodeMirror 6 | im Artikel nicht genannt | 0.35.1, Artikel vom 08./13.10.2026; Audit-Log, Secret-Manager, Zielgruppe Entwicklerteams | geöffnet [7] |
| **Desktop-Clients** | Desktop | je nach Produkt | TablePlus (nativ), Beekeeper Studio (Electron, Wechsel zu Tauri), DBeaver (Java), Sequel Ace (macOS), HeidiSQL (Windows) | bezahlt, Open Core oder GPL | Preise in Vergleichsartikeln widersprüchlich | Suche [6] |

## 2. Was der Markt zeigt

![Marktpositionierung](diagrams/marktpositionierung.svg)

| Nr. | Befund | Folge für phpdbadmin |
|---|---|---|
| B1 | **Der PHP-Klassiker stockt.** phpMyAdmin 5.2 ist offiziell nicht für PHP 8.4 und neuer freigegeben, Version 6 hat keinen Termin. | Wer Herd oder einen neuen Server mit PHP 8.4/8.5 nutzt, hat keinen offiziell passenden phpMyAdmin. |
| B2 | **phpPgAdmin ist faktisch verwaist.** Kein Commit seit 2023, Sicherheitsmeldung offen, neuere PostgreSQL-Versionen nur in Forks. | PostgreSQL im Browser heisst heute pgAdmin (schwer, Python) oder Adminer (knapp). |
| B3 | **Adminer deckt die Breite ab** und ist aktiv, gewinnt aber durch Einfachheit (eine Datei), nicht durch Führung. | Die Breite allein ist kein Alleinstellungsmerkmal; die Bedienung ist es. |
| B4 | **Moderne Oberflächen sind nicht PHP.** DbGate, CloudBeaver und die Desktop-Clients brauchen Node, Java, Docker oder eine Installation je Rechner. | Auf jedem PHP-Server und in Herd sofort lauffähig zu sein, ist ein Vorteil. |
| B5 | **Jaxon DbAdmin zielt seit Oktober 2026 in dieselbe Lücke** — PHP, vier Datenbanken, Laravel-App, Audit-Log. Frühphase (0.35), Kern Adminer, Bootstrap. | Direkter Mitbewerber; beobachten. Unterscheidung über Bedienung (P-01 bis P-08), Anmeldung ohne Passwort und den Laravel-Stack von 2026. |
| B6 | **KI-Hilfe für SQL wird Standard** (pgAdmin ab 9.13, DbGate 7.2.5, TablePlus-Plugin). | Als Option in Stufe 4, nur mit eigenem Schlüssel und nur mit dem Schema (R-23). |
| B7 | **Sicherheit ist Dauerthema**: pgAdmin mit mehreren CVEs je Release, Adminer CVE-2026-16434 (bis 5.5.0), phpPgAdmin mit offener XSS-Meldung. | Sicherheit ab Werk ist Verkaufsargument und Pflicht (N-02). |
| B8 | **Passwortlos kommt an**: Adminer 6.0 führt Anmeldung ohne Passwort ein. | Passkey und Code sind kein Alleinstellungsmerkmal mehr, aber Mindeststandard. |

## 3. Positionierung

| Option | Kern der Aussage | Bewertung |
|---|---|---|
| **A — «Ein Werkzeug für alle vier, das führt»** | phpMyAdmin und phpPgAdmin in einem, mit moderner, geführter Bedienung | trifft B1–B4; Kern des Vorschlags |
| **B — «Das Datenbank-Werkzeug für Laravel-Entwickler»** | Herd-Erkennung, Laravel-Migration aus Tabelle, Konventionen (`deleted_at`, `migrations`) | starkes Unterscheidungsmerkmal gegen Jaxon und Adminer; als Zusatz in Stufe 4 |
| **C — «Sicher im Team»** | Konten mit Passkey, Rechte je Verbindung, Protokoll, Schreibschutz der Produktion | nötig gegen B7; Teil von A |

**Vorschlag:** A mit C im Kern, B als Unterscheidungsmerkmal, sobald v1.0 steht. Leitsatz (Entwurf): *«Alle vier Datenbanken. Eine Oberfläche. Nichts passiert ungesehen.»*

## 4. Geschäftsmodelle im Markt

| Modell | Beispiele | Erlös |
|---|---|---|
| Frei (Open Source) | phpMyAdmin, Adminer, pgAdmin, phpPgAdmin | Spenden, Sponsoren |
| Open Core | DbGate (Premium, Team Premium), Beekeeper Studio, CloudBeaver | Abo je Benutzer |
| Bezahlt | TablePlus, DataGrip | Lizenz oder Abo |

Im Browser-Segment ist kostenlos die Regel. Ein Erlös wäre am ehesten mit Team-Funktionen möglich (Option B in der [Ideensammlung § 9](ideensammlung.md#9-wieviel--geschäftsmodell-offen)).

## 5. Risiken aus dem Markt

| Nr. | Risiko | Gegenmittel |
|---|---|---|
| RM-1 | Jaxon DbAdmin erreicht eine reife Version, bevor phpdbadmin v1.0 hat | früh nutzbare Stufen (Alpha lesend), Unterscheidung über Bedienung statt Funktionsliste |
| RM-2 | phpMyAdmin 6 erscheint mit neuer Oberfläche und PHP-8.4-Freigabe | bleibt auf MySQL/MariaDB beschränkt; die Breite über vier Datenbanken bleibt |
| RM-3 | Kostenloser Markt — kein Erlös | entschieden: Open Source unter MIT, Ziel ist der eigene Gebrauch und das Schaufenster (R-40) |
| RM-4 | Name zu nahe an phpMyAdmin | Name bewusst gewählt (R-04); Markenrecherche und eigenständiger Auftritt vor dem ersten öffentlichen Auftritt (F-10) |

## 6. Quellen (abgerufen 28.10.2026)

1. phpMyAdmin, Downloads — <https://www.phpmyadmin.net/downloads/> (geöffnet)
2. phpPgAdmin: GitHub-Issues <https://github.com/phppgadmin/phppgadmin/issues>, Packagist <https://packagist.org/packages/phppgadmin/phppgadmin>, Fork <https://github.com/ReimuHakurei/phppgadmin/releases> (Suche)
3. Adminer — <https://www.adminer.org/> (geöffnet)
4. pgAdmin, News — <https://www.pgadmin.org/news/>, <https://www.postgresql.org/about/news/pgadmin-4-v917-released-3356/> (Suche)
5. DbGate, News — <https://www.dbgate.io/news/>; GIGAZINE 15.08.2026 <https://gigazine.net/gsc_news/en/20260815-dbgate/> (Suche)
6. Vergleichsartikel 2026: <https://www.bytebase.com/blog/top-dbeaver-alternative/>, <https://queryglow.com/blog/dbeaver-sql-client>, <http://aidev.fit/en/tools/best-database-gui-tools.html> (Suche; Preise widersprüchlich)
7. «Jaxon DbAdmin: PHP finally gets a next-gen database manager», dev.to — <https://dev.to/tiaya/jaxon-dbadmin-php-finally-gets-a-next-gen-database-manager-244h> (geöffnet)
8. Endoflife phpMyAdmin — <https://endoflife.date/phpmyadmin> (Suche)
