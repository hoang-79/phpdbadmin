# Timebox 10b — Ergebnisse bearbeiten, Suchen und Ersetzen, Sicherung, Excel

**Stand:** 29.10.2026 · ✅ abgeschlossen — gebaut auf einem eigenen Zweig, zusammengeführt und im Browser geprüft.

## Ziel

Mit Daten arbeiten wie in einem Desktop-Werkzeug: Ergebnisse einer Abfrage direkt bearbeiten, Text in einer Tabelle suchen und ersetzen und über ein ganzes Schema finden, Datenbanken mit den Werkzeugen des Servers sichern und wiederherstellen, Tabellen als Excel ausgeben — gleich auf allen vier Treibern und ohne dass etwas ungesehen passiert.

## Umfang

| Nr. | Anforderung (CR-001 § 4) | Prio | Ergebnis |
|---|---|---|---|
| Q-05 | Ergebnisse bearbeiten, wenn die Abfrage eine Tabelle mit Primärschlüssel liest | C | ✅ Zellen ändern (Einfügen und Löschen bleiben im Raster) |
| D-08 | Suchen und Ersetzen; Suche über alle Tabellen einer Datenbank | C | ✅ beides |
| E-03 | Sicherung und Wiederherstellung über die Werkzeuge des Servers | S | ✅ vier Treiber, Versionsprüfung, sichtbares Überspringen |
| E-04 | Export als Excel (XLSX) | C | ✅ Tabelle und ganze Datenbank |

TB 10 ist geteilt: Objekte, Beziehungsdiagramm und EXPLAIN (S-06, S-07, Q-06) baut TB 10a. Für TB 10 gibt es keine Wireframes (Timebox-Plan § 1); gestaltet im Stil der Wireframes 05 (Raster), 06 (SQL-Vorschau als Seitenblatt), 10 (Editor) und 11 (Export und Import).

## Was entstanden ist

**Excel (E-04, Scheibe 1).** `Writers/XlsxWriter` schreibt Office Open XML ohne fremde Bibliothek: je Tabelle ein Blatt als Strom in eine Datei, erst am Ende packt `ZipArchive` (Inhaltstypen, Beziehungen, Mappe, Stile, Eigenschaften). Kopfzeile fett und fixiert, Spaltenbreite nach Art. Zahlen als Zahlen — genaue Typen (`decimal`, `bigint`) nur bis 15 Stellen, darüber als Text, weil Excel sonst rundet; Gleitkommaspalten mit allen Stellen. Datum, Zeitpunkt und Uhrzeit als Seriennummer mit ISO-Format (eine Zeitzone fällt weg, die Uhrzeit bleibt wie geliefert; vor dem 1.3.1900, «0000-00-00» und Zeiten über 24 Stunden als Text). NULL als leere Zelle, leerer Text als leere Textzelle. Text immer als Inline-Text, nie als Formel — `=HYPERLINK(…)` bleibt Text (keine Formel-Injektion, anders als CSV); Steuerzeichen, die XML nicht erlaubt, fallen weg, `\r` als Zeichenreferenz, `_xHHHH_` maskiert (Excel läse es sonst als Zeichencode). Zellen über 32'767 Zeichen gekürzt und im Ergebnis gezählt; über 1'048'576 Zeilen weiter auf einem Blatt «Name (2)». Format «Excel» auf der Seite «Export und Import»; das Exportformular nimmt nur noch die vier Exportformate an.

**Ergebnisse bearbeiten (Q-05, Scheibe 2).** Erkannt auf dem Server, im Zweifel nur lesen mit Grund:

1. `SingleTableSelect` zerlegt eng: `SELECT liste FROM [schema.]tabelle [[AS] alias] [WHERE …] [ORDER BY …] [LIMIT …] [OFFSET …] [FETCH …]`. Join, mehrere Tabellen, `GROUP BY`/`HAVING`, `DISTINCT`, `UNION`, `WITH`, Unterabfrage als Quelle, Index-Hinweise, `FOR UPDATE`, `INTO` und ausführbare MySQL-Kommentare (`/*! … */`) gelten als nicht erkennbar. Unterabfragen in `WHERE` stören nicht.
2. `EditableResult` löst Schema und Tabelle über den Katalog auf (MySQL/MariaDB: Datenbank der Abfrage; PostgreSQL: Suchpfad des Editors, dann `public`; Namen ungequotet ohne Rücksicht auf Gross- und Kleinschreibung, PostgreSQL klein gefaltet), ordnet jede Spalte des Ergebnisses einer Spalte der Tabelle zu (Stern = alle in Tabellenreihenfolge, Ausdrücke = keine) und prüft gegen den Treiber: Zahl und Namen der Spalten, dazu die Tabelle je Spalte aus `PDOStatement::getColumnMeta` (MySQL/MariaDB nennen den Alias, PostgreSQL und SQLite die Tabelle). Bearbeitbar sind Spalten der Tabelle beim ersten Vorkommen, nicht der Schlüssel, keine Binärdaten; der Primärschlüssel muss vollständig und skalar im Ergebnis stehen.
3. Angewendet wird über die Endpunkte des Rasters (Vorschau, eine Transaktion, Rückgängig mit `change_snapshots`) — kein zweiter Schreibweg. `ResultPanel` sammelt Änderungen (`useRowChanges`), zeigt «bearbeitbar · schema.tabelle» oder «nur lesen: Grund», öffnet die SQL-Vorschau und lädt danach dieselbe Abfrage mit ebenso vielen Zeilen neu; «Rückgängig machen» im Ergebnis. Mit offenen Änderungen lässt sich das Ergebnis nicht wechseln.

**Suchen und Ersetzen (D-08, Scheibe 3).** `SearchReplace` in gewählten Text-, Auswahl-, JSON- und Kennungsspalten ausserhalb des Primärschlüssels (ohne generierte): Teiltext (jedes Vorkommen) oder ganzer Wert, mit oder ohne Gross-/Kleinschreibung. Die Datenbank sucht grob vor — `LIKE … ESCAPE '!'` ohne Rücksicht auf Gross- und Kleinschreibung (MySQL/MariaDB `lower()`, PostgreSQL `ILIKE` auf Text, SQLite über die Faltfunktion), eine Obermenge für jede Kollation —, PHP entscheidet genau und ersetzt (`/iu`, Ersatz wörtlich, keine Rückverweise). So gelten auf allen vier Datenbanken dieselben Regeln, `%`, `_`, `!` und `\` wörtlich, «MÜLLER» trifft «Müller», aber nicht «Muller». Vorschau mit Zeilen, Zellen, zehn Beispielen vorher/nachher (lange Texte als Ausschnitt) und der Anweisung ohne Werte. Anwenden in einer Transaktion über den Primärschlüssel in Paketen, gesperrt (`FOR UPDATE`); jede Zeile muss genau einmal treffen, ungültiges JSON nach dem Ersetzen rollt alles zurück. Rückgängig über `change_snapshots` (gemeinsam mit dem RowEditor, neue Klasse `ChangeSnapshots`) bis `undo_rows` Zeilen — darüber nur nach ausdrücklicher Bestätigung «ohne Rückgängig»; mehr als `replace_rows` (Vorgabe 10'000) nie. Ein Protokolleintrag `data.replace` mit Spalten, Optionen und Zahlen, ohne Such- und Ersatztext. Seitenblatt «Suchen und Ersetzen» im Kopf des Rasters; danach steht im Fuss «Zuletzt angewendet · Rückgängig machen».

**Suche über ein Schema (D-08).** `SchemaSearch` durchsucht alle Tabellen eines Schemas mit derselben Bedingung wie die Suche im Raster (`TableData::searchHits`, gezählt bis zur Grenze) — der Link «im Raster zeigen» trifft genau diese Zeilen. Grenzen: Zeit für die ganze Suche (Vorgabe 10 s; jede Abfrage bekommt nur die verbleibende Zeit als Zeitlimit) und Treffer (Vorgabe 1'000); ist eine erreicht, nennt die Seite die ungesuchten Tabellen. Seite `workspace/Search` unter `c/{connection}/search?q=…`, Einstieg «Suchen in allen Tabellen» im Menü der Verbindung.

**Sicherung und Wiederherstellung (E-03, Scheibe 4).** Aufträge wie die Exporte aus TB 06 (`transfers` mit den neuen Arten `backup` und `restore`, Warteschlange, Frist 24 Stunden, Herunterladen nur für das eigene Konto); `BackupRunner`, `Tools`.

| | Sichern | Wiederherstellen |
|---|---|---|
| MySQL | `mysqldump --single-transaction --hex-blob --no-tablespaces --set-gtid-purged=OFF` (Routinen, Trigger, Ereignisse wahlweise) | `mysql --skip-commands` (Client ab 8.4), sonst der eigene SQL-Ausführer |
| MariaDB | `mariadb-dump` wie oben | `mariadb --sandbox` (Client ab 10.5.25/10.6.18/10.11.8), sonst der eigene SQL-Ausführer — Herd hat 10.11.6, also der eigene |
| PostgreSQL | `pg_dump --no-owner --no-privileges`, Custom-Format (Vorgabe) oder Text (`--clean --if-exists`) | `pg_restore --clean --if-exists --single-transaction --exit-on-error`; Text mit `psql --single-transaction`, `ON_ERROR_STOP`, im Modus `\restrict` mit eigenem geheimen Schlüssel (psql ab 18 bzw. 17.6/16.10/15.14/14.19, sonst abgelehnt) |
| SQLite | `VACUUM INTO` aus einer Nur-lesen-Verbindung, ohne Werkzeug | `sqlite3 .restore` (Backup-API, ganz oder gar nicht) nach `PRAGMA quick_check` der Datei |

- **Eine hochgeladene Sicherung ist fremder Inhalt.** Die Clients führen Befehle aus der Datei aus — `system`/`\!` (Shell auf dem phpdbadmin-Server), `tee`/`\o` (Dateien schreiben), `source`/`\i` (Dateien lesen). Deshalb nur mit Schutzschalter (oben); fehlt er, spielt phpdbadmin MySQL/MariaDB selbst ein (Anweisung für Anweisung wie der Import einer SQL-Datei, E-02) und lehnt das PostgreSQL-Textformat ab. Die `\restrict`-Zeilen von pg_dump selbst fallen ausserhalb von COPY-Daten weg; psql liest die vorbereitete Datei selbst (`--file`).
- **Versionsprüfung:** pg_dump nicht älter als der Server (Hauptversion), mysqldump nur für MySQL und mariadb-dump nur für MariaDB, nicht älter als der Server. Der Grund steht am Auftrag mit beiden Versionen.
- **Passwörter nie auf der Kommandozeile:** MySQL/MariaDB über eine eigene Optionsdatei (`--defaults-file`, 0600, im Temp-Ordner, danach gelöscht), PostgreSQL über `PGPASSWORD` in der Umgebung des Prozesses (geerbte `PGSERVICE`, `PGOPTIONS` entfernt). Verbunden wird zur geprüften Adresse (V-05); Prozesse mit `Symfony\Component\Process\Process` und Argumentliste, Zeitgrenze (Hintergrund 3'600 s, in der Anfrage 280 s), abbrechbar.
- **Wiederherstellen** nur mit Recht «schreiben», nie über eine schreibgeschützte Verbindung, mit dem Haken «Tabellen gleichen Namens werden ersetzt», in Produktion mit dem Namen der Verbindung; Art der Datei am Anfang erkannt (`PGDMP`, `SQLite format 3`, Text) und gegen den Treiber geprüft. Ein Eintrag `data.restore` (auch gescheitert, mit `failed`), die Sicherung selbst als `transfer.backup`.
- **Werkzeuge** in `config/phpdbadmin.php` (`backup.tools`, je `PHPDBADMIN_TOOL_*` setzbar): ein Name wird im PATH gesucht, danach in Herd, Herd-PostgreSQL (neueste Version), Homebrew, Postgres.app, `/usr/local/bin`, `/usr/bin` (`PHPDBADMIN_TOOL_DIRECTORIES`) — PHP-FPM hat oft einen schmalen PATH. Fehlt eines, zeigt die Seite «Sicherung und Wiederherstellung» es mit dem Namen der Einstellung.
- Seite `workspace/transfers/Backup` unter `c/{connection}/transfers/backup`, verlinkt von «Export und Import».

**Werte kommen wörtlich an.** Laravels `TrimStrings` und `ConvertEmptyStringsToNull` wirken auch auf JSON-Anfragen: Leerer Text wurde beim Bearbeiten im Raster zu NULL, Leerzeichen am Rand fielen weg (gefunden mit einem Test, Fehler seit TB 04). Beide nehmen jetzt die Adressen `…/changes` und `…/replace` aus (`bootstrap/app.php`). Ausserdem bearbeitet das Raster gekürzten Text (über 1'000 Zeichen) nicht mehr in der Zelle — gespeichert würde sonst nur der Anfang; dafür gibt es das Zeilen-Detail.

## Neue Adressen

| Methode | Adresse | Name |
|---|---|---|
| POST | `c/{connection}/s/{schema}/o/{object}/replace/preview` | `workspace.object.replace.preview` |
| POST | `c/{connection}/s/{schema}/o/{object}/replace` | `workspace.object.replace` |
| GET | `c/{connection}/search` | `workspace.search` |
| GET | `c/{connection}/transfers/backup` | `workspace.transfers.backup` |
| POST | `c/{connection}/transfers/backup` | `workspace.transfers.backup.store` |
| POST | `c/{connection}/transfers/restore` | `workspace.transfers.restore` |

Ergebnisse bearbeiten braucht keine eigene Adresse: `workspace.sql.run` liefert je Ergebnis `source` (Herkunft, bearbeitbare Spalten, Grund), angewendet wird über `workspace.object.changes*`.

## Abnahmekriterien

| Anforderung | Kriterium (CR-001) | Nachweis |
|---|---|---|
| Q-05 | Test | `EditableResultTest` je Treiber: Alias, Schema, Ausdruck, Wahrheitswert-Spalte → bearbeitbar, Änderung über den RowEditor wirkt und lässt sich rückgängig machen; Join, Ausdruck im Schlüssel, fehlender Schlüssel, Tabelle ohne Schlüssel, Sicht, `GROUP BY`, nur Schlüssel → nur lesen mit Grund; Leserecht und Schreibschutz mit Grund. `SingleTableSelectTest` (27 Formen), `SqlControllerTest` (Herkunft im Ergebnis, Änderung über die Endpunkte, Leser 403) |
| D-08 | Test je Treiber | `SearchReplaceTest` je Treiber: Vorschau schreibt nichts, zählt «MÜLLER»/«Müller» (nicht «Muller»); eine Transaktion, ein Protokolleintrag ohne Texte, Rückgängig = Ausgangszustand; `50%` trifft nicht «500», `_case_` nicht «XcaseX», Backslash, `!%`, Emoji (jedes Vorkommen), Gross-/Kleinschreibung, ganzer Wert, leerer Ersatz; ungültiges JSON und zu viele Zeilen ändern nichts. `SchemaSearchTest` je Treiber: Treffer je Tabelle, Platzhalter wörtlich, Treffer- und Zeitgrenze. `SearchReplaceControllerTest`: Leerzeichen am Rand und leerer Ersatz kommen an, Rückgängig über das Raster, Leser darf Vorschau, nicht Anwenden, Schreibschutz 403, Produktion nur mit Namen |
| E-03 | Test überspringt sichtbar, wenn das Werkzeug fehlt; Versionsprüfung | `BackupRunnerTest`: Sicherung → Wiederherstellung in eine leere Datenbank → gleiche Daten (Umlaute, Emoji, Binärdaten, `0.30000000000000004`, JSON, zusammengesetzter Schlüssel, Tabelle ohne Schlüssel), Sicht und Trigger mit — MySQL, MariaDB, PostgreSQL (Custom und Text), SQLite; Datei mit `\!`, `system`, `tee`, `\o` legt keine Datei an (MySQL, MariaDB, PostgreSQL); mariadb-dump für MySQL abgewiesen mit Versionen; fehlendes Werkzeug erklärt. Fehlt ein Werkzeug, `markTestSkipped('… nicht gefunden (Pfad in PHPDBADMIN_TOOL_… setzen) — übersprungen, nicht bestanden')`. `ToolsTest` (Versionen lesen, Paare prüfen), `BackupControllerTest` (Seite, Download nur eigenes Konto, Bestätigung, Leser und Schreibschutz 403, Produktion mit Namen, falsche Dateiart) |
| E-04 | Test | `XlsxExportTest` je Treiber: Zip mit allen Teilen, jedes XML lesbar, Inhaltstypen und Beziehungen je Blatt; Kopfzeile fett, Zahlen, Wahrheitswerte, Datum als Seriennummer mit Format, NULL leer, leerer Text als Zelle, Sonderzeichen wörtlich, keine Formel, Steuerzeichen weg, `_x0041_` maskiert, 32'767 Zeichen; ganze Datenbank = ein Blatt je Tabelle, Sicht zuletzt. Gegengelesen mit openpyxl |

## Messwerte

200'000 Zeilen (`id`, Text mit 200 Zeichen), je Treiber gegen Herd, 29.10.2026 (Probe, nicht im Repository):

| | MySQL 8.4 | MariaDB 10.11 | PostgreSQL 18 (Custom) | PostgreSQL 18 (Text) |
|---|---|---|---|---|
| Sichern | 0,5 s · 40,3 MB | 0,7 s · 40,5 MB | 0,2 s · 0,6 MB | 0,2 s · 39,6 MB |
| Wiederherstellen | 1,8 s (mysql) | 1,1 s (eigener Ausführer) | 1,8 s (pg_restore) | 2,0 s (psql) |

## Gestaltungsentscheide (statt Wireframe-Abweichungen)

| Bereich | Entscheid | Grund |
|---|---|---|
| Ergebnis bearbeiten | nur Zellen; Einfügen, Duplizieren und Löschen bleiben im Raster | ein Ergebnis zeigt eine Auswahl; neue Zeilen erschienen nicht darin |
| Ergebnis bearbeiten | Schlüsselspalten nur lesen | Rückgängig und Konfliktprüfung hängen am Schlüssel der Zeile |
| Suchen und Ersetzen | Seitenblatt wie die SQL-Vorschau (Wireframe 06), Beispiele als Tabelle vorher/nachher | dieselbe Bedienung wie beim Bearbeiten |
| Suche über ein Schema | eigene Seite statt Feld im Navigator | Navigator und Schema-Seite gehören TB 10a bzw. TB 05; Einstieg über das Menü der Verbindung |
| Sicherung | eigene Seite neben «Export und Import» | Wiederherstellen ersetzt ganze Tabellen — getrennt vom tabellenweisen Import |

## Entscheide in der Timebox

- **Wiederherstellen ohne geschützten Client** mit dem eigenen SQL-Ausführer statt mit dem Client — der Client führte sonst Befehle aus der Datei auf dem phpdbadmin-Server aus (neue Bedrohung T-20). psql ohne `\restrict` lehnt das Textformat ab, das Custom-Format geht immer.
- **Excel-Formeln** können nicht entstehen: Zellen sind Zahlen, Wahrheitswerte, Datum oder Inline-Text, nie `<f>` (T-21). Der CSV-Export bleibt, wie er ist (Daten unverändert, TB 06).
- **Suche über ein Schema** mit der Bedingung des Rasters (bei MySQL/MariaDB nach der Kollation, also auch ohne Akzente) statt mit der genauen Regel von Suchen und Ersetzen — damit der Link genau die gezählten Zeilen zeigt. Suchen und Ersetzen entscheidet dagegen in PHP, gleich auf allen vier.
- **Ersetzen über 1'000 Zeilen** nur nach Bestätigung «ohne Rückgängig», über 10'000 gar nicht (SQL-Editor) — eine Anfrage soll nicht minutenlang laufen.

## Tests

Neu: `XlsxExportTest` (8), `SingleTableSelectTest` (27, Unit), `EditableResultTest` (16), `SearchReplaceTest` (20), `SchemaSearchTest` (8), `SearchReplaceControllerTest` (5), `ToolsTest` (17, Unit), `BackupRunnerTest` (10), `BackupControllerTest` (6); erweitert: `TransferControllerTest` (+2), `SqlControllerTest` (+3), `RowChangeControllerTest` (+1, Leerzeichen und leerer Text). Je Treiber gegen die Herd-Dienste: MySQL 16, MariaDB 15, PostgreSQL 17, SQLite 14 Fälle, dazu 17 HTTP-Tests auf SQLite; zweites Konto als Leser (403) und fremdes Konto (404).

**Stand der Suite:** 961 Tests, 941 bestanden, 20 übersprungen (die schweren Proben, bewusst nur auf Wunsch; auf diesem Rechner fehlt kein Werkzeug). Pint, Larastan Stufe 7, `vp check` und `vue-tsc` ohne Befund.

## Commits

`a7bdbe5` Scheibe 1: Excel · `7e1faaf` Scheibe 2: Ergebnisse bearbeiten · `3b8208f` Scheibe 3: Suchen und Ersetzen, Suche über ein Schema · `5ae2fb0` Scheibe 4: Sicherung und Wiederherstellung · `b1e0707` Doku · `86b1209` Wahrheitswerte im bearbeitbaren Ergebnis · Commits im Protokoll (dieser Stand).

## Offen

- Sichtprüfung im Browser bei 1440 und 375 px (Ergebnis bearbeiten, Seitenblatt «Suchen und Ersetzen», Seiten «Suchen» und «Sicherung») — Hauptstrang.
- Ergebnis bearbeiten: Einfügen und Löschen im Ergebnis; Abfragen mit `JOIN` auf eine zweite Tabelle nur lesend.
- Suchen und Ersetzen nur in den gefilterten Zeilen des Rasters; Ersetzen mit regulären Ausdrücken.
- Sicherung über einen SSH-Tunnel (TB 11): die Werkzeuge verbinden heute zur geprüften Adresse, nicht durch den Tunnel.
- Gepackte Sicherungen (`.gz`) hochladen; Sicherung einzelner Schemas bei PostgreSQL; Wiederherstellung in eine neue Datenbank (legt sie an).
- Herd liefert mariadb 10.11.6 ohne `--sandbox` — mit einem neueren Client stellt MariaDB über den Client wieder her (beide Wege getestet über MySQL bzw. MariaDB).

## Nach dem Zusammenführen

Zusammengeführt mit `3d9fe36`. Sicherung und Wiederherstellung sind für Verbindungen über einen SSH-Tunnel (TB 11) gesperrt, mit Hinweis auf der Seite — die Werkzeuge verbänden sonst direkt zur Adresse hinter dem Tunnel, aus Sicht des phpdbadmin-Servers (Test). Durchlauf im Browser (MariaDB): Abfrage auf `customers` als «bearbeitbar · phpdbadmin_demo.customers» erkannt, Zelle geändert, Änderungsleiste mit «SQL ansehen» und «Verwerfen»; Suche «Bern» über das Schema: 88 Treffer in zwei Tabellen in 0,5 s mit Link ins gefilterte Raster; Sicherung der Demo-Datenbank (121 KB SQL) in der Anfrage fertig. **Befund:** Der Dateiname der Sicherung trug die Uhrzeit in UTC («-0148»), die Liste daneben die Ortszeit («fertig 02:48») — Dateinamen von Sicherungen und Exporten folgen jetzt der Zeitzone des Kontos (Test um 23:30 UTC).

## Gelernt

- Laravels `TrimStrings` und `ConvertEmptyStringsToNull` greifen auch in JSON-Anfragen — in einem Datenbankwerkzeug verändern sie Werte (Lektion im Vault).
- Symfony Process reicht die Eingabe eines Prozesses nur in den Runden weiter, in denen man nach ihm fragt; eine Schleife mit 250 ms Pause machte aus 0,5 s Wiederherstellung 72 s (Lektion im Vault).
- Datenbank-Clients führen Befehle aus Eingabedateien aus (`\!`, `system`, `tee`) — Uploads nie ohne Schutzschalter einspielen (Lektion im Vault).
- PHPStan rechnete in einem Generator mit konstanter Paketgrösse die Schleife für einmalig («`null !== null` immer falsch»); die Paketgrenze am Ergebnis (`$records->count()`) statt an einem Zähler prüfen.
