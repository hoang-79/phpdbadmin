# Sicherheit und Bedrohungen — phpdbadmin (F-7)

**Stand:** 28.10.2026 · v0.1, nachgeführt 29.10.2026 (T-20, T-21 aus TB 10b) — Bedrohungsmodell für ein Werkzeug, das Zugang zu allen verwalteten Datenbanken hat. Was mit einer Probe belegt ist, verweist auf `SecurityProbeTest` bzw. `ConnectionProbeTest`; der Rest ist Vorgabe für Foundation und Timeboxes und wird dort mit Tests belegt. Vorbild für die Prüfung vor v1.0: White-Box-Durchgang nach OWASP-Kategorien (Lehre aus Basalto und dem AIB Portal: grüne Pipeline ist keine Sicherheitsprüfung).

## 1. Was zu schützen ist

| Gut | Warum heikel |
|---|---|
| Zugangsdaten der verwalteten Datenbanken | wer sie hat, hat die Daten — auch ohne phpdbadmin |
| Daten in den verwalteten Datenbanken | Personendaten, Geschäftsdaten; phpdbadmin zeigt sie an und kann sie ändern |
| Konten und Sitzungen von phpdbadmin | ein übernommenes Konto erbt alle Rechte auf alle Verbindungen |
| Netz hinter dem Server | phpdbadmin baut Verbindungen zu Hosts auf — ein Sprungbrett, wenn die Hosts frei wählbar sind |
| Dateien auf dem App-Server und dem DB-Server | SQLite-Pfade, `ATTACH`, Dateifunktionen der Server |
| Protokoll | enthält SQL und damit Daten |

## 2. Bedrohungen und Gegenmittel

| Nr. | Bedrohung | Gegenmittel | Beleg / Anforderung |
|---|---|---|---|
| T-01 | Konto übernommen (Passwort erraten, Phishing) | kein Passwort; Code einmalig, gedrosselt, gehasht; Passkeys; Sitzung neu nach Anmeldung | A-01 bis A-03 (TB 00) |
| T-02 | Passwort-Endpunkte von Fortify bleiben offen | `POST /login` und Passwortbestätigung ausdrücklich abweisen | F-6, A-01 |
| T-03 | Zugangsdaten gelangen in den Browser | nie als Prop oder JSON; Formulare zeigen nur «gesetzt»; Test über die ausgelieferte Antwort | V-03 |
| T-04 | Zugangsdaten im Klartext auf der Platte | `encrypted`-Cast mit `APP_KEY`; Wahl «nicht speichern, in der Sitzung fragen» | V-03 |
| T-05 | Sprungbrett ins interne Netz (SSRF) | erlaubte Hosts je Installation; Hostname einmal auflösen und die geprüfte Adresse verbinden (gegen DNS-Rebinding) | V-05 |
| T-06 | Schreiben trotz Schreibschutz | Schichten: Lesekonto empfohlen, Nur-lesen-Transaktion je Anweisung, vorbereitet, SQLite `OPEN_READONLY` | **belegt** F-7 (alle vier Treiber) |
| T-07 | Mehrfachanweisungen schmuggeln Schreibzugriffe | nur vorbereitete Anweisungen, nie `unprepared()` für Eingaben | **belegt** F-7 |
| T-08 | SQLite: fremde Dateien anhängen und lesen (`ATTACH`) | `Pdo\Sqlite::setAuthorizer()`, nur im erlaubten Ordner; SQLite-Pfade nur in erlaubten Ordnern, Symlinks aufgelöst | **belegt** F-7, V-05 |
| T-09 | Dateien des DB-Servers lesen (`LOAD_FILE`, `pg_read_file`) über ein Konto mit zu vielen Rechten | Kontorechte beim Verbinden prüfen und sichtbar warnen; Lesekonten empfehlen | **belegt** F-7 (MariaDB, PostgreSQL in Herd), neu V-10 |
| T-10 | Dateien des App-Servers hochladen (`LOAD DATA LOCAL INFILE`) | Client-Option bleibt aus | **belegt** F-7 (Fehler 3948) |
| T-11 | Daten in Fehlermeldungen und Protokollen | Laravel setzt Bindungen in Meldungen ein — Meldungen maskieren, bevor sie angezeigt, protokolliert oder gemeldet werden | **belegt** F-7, B-04, N-02 |
| T-12 | Lange Abfragen legen den Server lahm | Zeitlimit je Anweisung (MySQL, MariaDB, PostgreSQL); SQLite im Arbeitsprozess mit Zeitgrenze; Abbrechen von aussen | **belegt** F-2, Q-03, Q-09 |
| T-13 | Riesige Ergebnisse sprengen den Speicher | Zeilengrenze je Ergebnis; Export in Paketen | **belegt** F-8, Q-03, E-01 |
| T-14 | XSS über Inhalte der Datenbank (Tabellennamen, Zellwerte) | Vue maskiert; nie `v-html` für Datenbankinhalte; CSP | N-02 |
| T-15 | CSRF auf schreibende Aktionen | Laravel 13 `PreventRequestForgery`, Test mit echter Middleware | N-02 |
| T-16 | Fremde Verbindung aufrufen (Kennung im Pfad) | Rechte vor der Routenbindung prüfen, fremde Verbindung = 404; Test mit zweitem Konto | A-07, V-09 |
| T-17 | phpdbadmin verwaltet sich selbst am System vorbei | App-Datenbank nie als Verbindung anbieten | R-17 |
| T-18 | Probe-Seiten in einer Installation | `routes/probe.php` nur in `local`; in TB 00 entfernt oder übernommen | F-3, F-4 |
| T-19 | Fremde übernehmen die Ersteinrichtung (erster Besucher einer frisch installierten Seite wird Administrator) | Einrichtung im Browser nur mit dem Einrichtungsschlüssel aus einer Datei auf dem Server (0600, nach der Einrichtung gelöscht), nur solange es kein Konto gibt, gedrosselt, Fehlversuche im Protokoll; alternativ per Konsole | **belegt** TB 00, A-06 |
| T-20 | Eine hochgeladene Sicherung führt Befehle auf dem phpdbadmin-Server aus: die Clients `mysql`, `mariadb` und `psql` kennen `system`/`\!` (Shell), `tee`/`\o` (Dateien schreiben), `source`/`\i` (Dateien lesen) | Clients nur mit Schutzschalter (`mysql --skip-commands`, `mariadb --sandbox`, psql im Modus `\restrict` mit eigenem geheimem Schlüssel); ohne Schutz spielt phpdbadmin MySQL/MariaDB selbst ein, psql-Textformat abgelehnt; Wiederherstellen nur mit Recht «schreiben»; Passwörter nie auf der Kommandozeile (Optionsdatei 0600 bzw. `PGPASSWORD`) | **belegt** TB 10b, E-03 (`BackupRunnerTest`: Markierungsdateien bleiben aus) |
| T-21 | Formel-Injektion: ein Zellwert wie `=HYPERLINK(…)` wird in der Tabellenkalkulation zur Formel | Excel-Export schreibt Text nur als Inline-Text, nie als Formel; CSV bleibt unverändert (Daten wörtlich, TB 06) — Hinweis für TB 08 | **belegt** TB 10b, E-04 (`XlsxExportTest`) |

## 3. Restrisiken

- **Der Server ist die Grenze.** Was ein Datenbank-Konto darf, darf phpdbadmin auch; Schreibschutz und Warnungen ersetzen kein Lesekonto.
- **Mehrere Personen auf einer Installation** sehen, was ihre Rechte je Verbindung zulassen — Rechte auf Ebene einzelner Tabellen gibt es in phpdbadmin nicht (dafür sind die Konten des Servers da).
- **KI-Hilfe (Q-08)** sendet das Schema an einen Anbieter — nur eingeschaltet, nur mit eigenem Schlüssel, nie Daten.
