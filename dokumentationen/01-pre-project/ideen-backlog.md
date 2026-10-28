# Ideen-Backlog — phpdbadmin

**Stand:** 28.10.2026 · erste Sammlung. Alle Ideen mit Nutzen, Herkunft und MoSCoW-Vorschlag. Was in [CR-001 § 4](../00-change_request/CR-001-phpdbadmin.md#4-anforderungen) als Anforderung steht, trägt deren Nummer; die übrigen sind Kandidaten für spätere Change Requests. Herkunft: **H** Hoangs Auftrag · **M** Marktbild · **F** Funktionsraster · **L** Lektionen aus Basalto und Fristo · **C** Vorschlag Claude.

## 1. Anmeldung und Konten

| Idee | Nutzen | Herkunft | MoSCoW | Bezug |
|---|---|---|---|---|
| Anmeldung nur mit Passkey oder Code per Mail | kein Passwort, das man verlieren oder erraten kann | H | Must | A-01 bis A-03 |
| Passkey nach der ersten Code-Anmeldung anbieten | schnellere Anmeldung im Alltag | C | Should | A-03 |
| Einladung mit Ablauf statt Registrierung | keine fremden Konten | C | Must | A-06 |
| Rechte je Verbindung (lesen, schreiben, verwalten) | Teams ohne geteilte DB-Passwörter | M | Should | A-07 |
| Sitzungen sehen und beenden | verlorenes Gerät sperren | C | Could | A-08 |

## 2. Verbindungen

| Idee | Nutzen | Herkunft | MoSCoW | Bezug |
|---|---|---|---|---|
| Umgebungsfarbe und Schreibschutz für die Produktion | Verwechslung lokal/Produktion verhindern | C | Must | V-04 |
| Rotes Favicon und Präfix im Seitentitel bei Produktion | Gefahr auch im Browser-Tab sichtbar | C | Should | V-04 |
| «Passwort nicht speichern» je Verbindung | heikle Server ohne gespeicherte Zugangsdaten | C | Should | V-03 |
| Erlaubte Hosts und Ordner | kein Sprungbrett ins interne Netz | L | Must | V-05 |
| Herd-Dienste und `.env` der Herd-Sites erkennen | Verbindung mit einem Klick | C | Could | V-07 |
| SSH-Tunnel | interne Server erreichen | F | Could | V-08 |
| Verbindungsliste aus phpMyAdmin-Konfiguration übernehmen | Umstieg erleichtern | C | Won't | — |

## 3. Bedienung

| Idee | Nutzen | Herkunft | MoSCoW | Bezug |
|---|---|---|---|---|
| Befehlspalette ⌘K | alles mit der Tastatur erreichen | C | Must | O-02 |
| Reiter, die das Neuladen überleben | Arbeit nicht verlieren | C | Should | O-03 |
| Zeilen-Detail als Seitenschublade mit allen Feldern und Beziehungen | lange Werte und Bezüge auf einen Blick | C | Should | D-05 |
| Karten statt Tabelle auf dem Telefon | lesbar ohne waagrechtes Scrollen | L | Could | O-07 |
| Kürzel-Übersicht mit `?` | Tastatur lernen | C | Should | O-05 |

## 4. Daten

| Idee | Nutzen | Herkunft | MoSCoW | Bezug |
|---|---|---|---|---|
| Änderungen sammeln, SQL-Vorschau, einmal anwenden | nichts passiert ungesehen | H, C | Must | D-04 |
| Rückgängig für angewendete Datenänderungen | Fehler in Sekunden beheben | C | Should | D-09 |
| Betroffene Zeilen vor UPDATE und DELETE zählen | Ausmass vor dem Ausführen sehen | C | Must | Q-07 |
| Fremdschlüssel vorwärts und rückwärts folgen | Zusammenhänge ohne SQL | F | Should | D-03 |
| Spaltenstatistik (Füllgrad, NULL-Anteil, kleinster und grösster Wert, häufigste Werte) | Datenqualität messen statt Bildschirme ansehen | L | Could | — |
| Abfrage-Parameter (`:von`, `:bis`) mit Formular | gespeicherte Abfragen wiederverwenden | C | Could | Q-04 |
| Vergleich zweier Abfrageergebnisse | «was hat sich geändert?» | C | Could | — |
| Testdaten erzeugen (Faker) für eine Tabelle | lokale Entwicklung | C | Could | — |
| Datenmaskierung für Bildschirmfreigaben (E-Mail, Telefon) | Vorführen ohne Datenschutzproblem | M | Could | — |

## 5. Struktur und SQL

| Idee | Nutzen | Herkunft | MoSCoW | Bezug |
|---|---|---|---|---|
| SQL-Vorschau für jede Strukturänderung | DDL verstehen und prüfen | H, C | Must | S-02, S-03 |
| Gesundheitsprüfung: Fremdschlüssel ohne Index, Tabellen ohne Primärschlüssel, `TEXT` an der 64-KB-Grenze, `timestamp` ohne Vorgabe | Fallen aus Basalto früh sehen | L | Could | — |
| Laravel-Konventionen erkennen (`migrations`, `deleted_at`, `created_at`) — Filter «ohne gelöschte» | Laravel-Daten richtig lesen | C | Could | — |
| Laravel-Migration aus Tabelle | Bestehendes in Code überführen | C | Could | S-09 |
| Schemavergleich mit Änderungsskript | Stände angleichen | F | Could | S-08 |
| Beziehungsdiagramm | Datenmodell verstehen | F | Should | S-07 |
| EXPLAIN als Baum mit Hinweisen | langsame Abfragen verstehen | F | Should | Q-06 |
| SQL-Hilfe mit KI (eigener Schlüssel, nur Schema) | Abfragen schneller schreiben | M | Could | Q-08 |
| «Erkläre diese Tabelle» mit KI | fremde Datenbanken verstehen | C | Won't (vorerst) | — |

## 6. Export, Import, Betrieb

| Idee | Nutzen | Herkunft | MoSCoW | Bezug |
|---|---|---|---|---|
| Export CSV, JSON, SQL im Hintergrund | grosse Tabellen ohne Zeitüberschreitung | F | Must | E-01 |
| CSV-Import mit Spaltenzuordnung und Probelauf | Fehler vor dem Schreiben sehen | F, L | Must | E-02 |
| Kopieren als TSV, Markdown, INSERT | schnell in Ticket oder Doku | C | Should | E-05 |
| Sicherung mit `mariadb-dump`, `mysqldump`, `pg_dump` | Rückweg vor grossen Änderungen | F | Should | E-03 |
| Protokoll aller schreibenden Aktionen | nachvollziehen, wer was geändert hat | M | Must | B-04 |
| Docker-Image | Betrieb neben Datenbanken in Containern | M | Could | R-41 |
| Öffentliche Demo mit SQLite-Beispieldatenbanken | ausprobieren ohne Installation | C | Should | R-30 |

## Architektur-Hinweis

Fast jede Idee berührt die **Dialekt-Schicht**: Was eine Funktion je Datenbank anders tun muss, gehört dorthin und bekommt einen Vertragstest, der gegen alle vier Treiber läuft. Ideen, die nur auf einem Treiber sinnvoll sind (Events in MySQL, Sequenzen in PostgreSQL, `PRAGMA` in SQLite), erscheinen nur dort — mit derselben Bedienung.
