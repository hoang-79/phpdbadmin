# Timebox 06 — Export und Import im Hintergrund, Statistik

**Stand:** 29.10.2026 · ✅ abgeschlossen — gebaut auf einem eigenen Zweig (parallel zu TB 02/03/07), zusammengeführt mit `2f0301c`, im Browser geprüft.

## Ziel

Tabellen und ganze Datenbanken als CSV, JSON oder SQL exportieren und CSV- oder SQL-Dateien importieren — in Paketen, im Hintergrund, mit Probelauf, der nichts schreibt, und aktueller Statistik danach; gleich auf allen vier Treibern.

## Umfang

| Anforderung | Priorität im Plan | Ergebnis |
|---|---|---|
| E-01 Export (Tabelle, Datenbank; CSV, JSON, SQL) | Must | ✅ — Export einer **Abfrage** folgt mit dem SQL-Editor (TB 03), «gefilterte Zeilen» mit dem Knopf im Objektkopf |
| E-02 Import (CSV mit Zuordnung und Probelauf, SQL-Datei) | Must ↓ | ✅ beides, SQL-Datei samt Zerleger |
| E-06 Statistik nach Importen und auf Knopfdruck | Should | ✅ |
| B-07 Warteschlange, ohne Worker synchron mit Grenze | Should | ✅ |

Wireframe-Bereich 11 (Export und Import).

## Was entstanden ist

**Export** (`App\Services\Transfer\Exporter`, ADR-007). Gelesen wird im Schreibschutz-Wächter (`Dialect::runReadOnly`, eine Nur-lesen-Transaktion für den ganzen Export) und in Paketen zu 5'000 Zeilen über den Primärschlüssel — auch zusammengesetzt, `(a, b) > (x, y)` ausgeschrieben. Ohne Schlüssel und für Sichten liest ein Cursor, der nie das ganze Ergebnis hält: PostgreSQL `DECLARE … CURSOR` / `FETCH 5000`, MySQL und MariaDB ungepuffert, SQLite streamt ohnehin. Einen Versatz (`OFFSET`) gibt es nicht — ohne eindeutige Sortierung liefert er Zeilen doppelt oder gar nicht. Geschrieben wird als Strom nach `storage/app/private/transfers/<Auftrag>/`.

- **CSV:** Trennzeichen nach Wahl, UTF-8 mit BOM für Excel, Kopfzeile; NULL ist ein leeres Feld ohne Anführungszeichen, leerer Text `""` — so kommen beide beim Re-Import zurück. Binärspalten als Base64 oder weggelassen. CSV nur für eine Tabelle.
- **JSON:** Liste von Objekten, eine Zeile je Objekt; ganze Datenbank als Objekt «Tabelle → Liste». Zahlen und Wahrheitswerte als solche, JSON-Spalten eingebettet (MySQL, PostgreSQL), Binärdaten als Base64.
- **SQL:** Struktur, Daten oder beides, wahlweise mit `DROP … IF EXISTS`. `INSERT` zu 100 Zeilen bzw. 1 MB, Werte über Laravels `escape` je Treiber (`x'…'` bzw. `'\x…'::bytea`, `true`/`1`). Tabellen nach Fremdschlüsseln geordnet; MySQL/MariaDB und SQLite ohne Schema im Namen (Import in eine andere Datenbank) und mit abgeschalteter Fremdschlüsselprüfung; PostgreSQL mit Schema wie die DDL des Dialekts, Sequenzen der `serial`-Spalten vorher, `setval` nach den Daten, Fremdschlüssel am Ende per `ALTER TABLE` (Verweise auf die eigene Tabelle und Kreise gehen so auch). MySQL-Sichten ohne `DEFINER` und ohne Schema vor den Spalten. Nicht enthalten: Trigger, Routinen, Ereignisse (SQLite-Trigger würden beim Einspielen feuern) — dafür E-03.
- Kommazahlen verlustfrei: `(string)` rundet in PHP auf 14 Stellen, auch in Laravels `escape`; der Export nimmt die kürzeste exakte Darstellung (`var_export`).

**Import** (`CsvImporter`, `SqlImporter`). Hochladen legt einen Entwurf an; danach Zuordnung, Probelauf, Import (Wireframe 11).

- **CSV:** Kopfzeile, Trennzeichen und Kodierung (UTF-8 mit/ohne BOM, sonst Windows-1252) erkennt die Analyse und lassen sich ändern; Zuordnung nach Namen vorgeschlagen (`E-Mail` → `email`). Eigener Leser statt `fgetcsv` (NULL gegen leeren Text, Zeilennummer des Satzanfangs auch bei Umbrüchen in Feldern). Eingefügt wird in Paketen zu höchstens 1'000 Zeilen (weniger bei vielen Spalten, unter den Grenzen für gebundene Werte); scheitert ein Paket, finden Einzelversuche hinter einem **Sicherungspunkt, der danach freigegeben wird**, die schuldigen Zeilen — Laravels verschachtelte Transaktionen geben ihre Sicherungspunkte nie frei, und PostgreSQL bricht ohne sie die ganze Transaktion ab. Fehler mit Zeilennummer und Grund (leer und NOT NULL, kein Base64, falsche Feldzahl, Ablehnung der Datenbank mit ihrer Meldung ohne SQL). Wahrheitswerte aus Tabellenkalkulationen (`ja`, `WAHR`, `x`) → 1/0. Leere Pflichtfelder mit Vorgabe oder Autowert setzen `DEFAULT` (SQLite kennt das nicht). Binärwerte als Strom gebunden (`PDO::PARAM_LOB`) — als Text verlöre PostgreSQL am Nullbyte und SQLite speicherte TEXT.
- **Probelauf schreibt nichts:** alle Zeilen in einer Transaktion einfügen, dann zurückrollen; auf Tabellen ohne Transaktionen (MyISAM) nur die Werte prüfen. Import: «Fehlerzeilen überspringen» oder «bei einem Fehler nichts importieren» — beides in einer Transaktion.
- **SQL-Datei:** eigener Zerleger `SqlScript` in Stücken von 1 MB — Zeichenketten (`''`, bei MySQL/MariaDB `\'`, bei PostgreSQL `E'…'`), Namen in `"…"`, `` `…` ``, `[…]`, Kommentare (`--` mit Leerraum bei MySQL, `#`, verschachtelt bei PostgreSQL), ausführbare `/*! … */`, `$$`-Blöcke, `DELIMITER` des MySQL-Clients, `BEGIN … END` in `CREATE TRIGGER/PROCEDURE/FUNCTION/EVENT`. Jede Anweisung einzeln vorbereitet (T-07); was MySQL nicht vorbereiten kann (Fehler 1295: `LOCK TABLES`, `CREATE TRIGGER` …), läuft ohne Vorbereitung — die Verbindung lässt nie mehrere Anweisungen in einem Aufruf zu. PostgreSQL und SQLite in einer Transaktion (eigene `BEGIN`/`COMMIT` der Datei übergangen, SQLite mit Fremdschlüsselprüfung am Ende); MySQL/MariaDB Anweisung für Anweisung, bei einem Fehler bleibt, was davor lief — die Meldung sagt es. Probelauf: nur zerlegen und zählen, Warnung vor `DROP`, `TRUNCATE`, `DELETE`/`UPDATE` ohne `WHERE`.
- **Schreiben nur mit Recht:** `Gate::authorize('write', $connection)` (vorläufige `DatabaseConnectionPolicy::write`: Besitzer oder «verwalten»; die vollständige Policy aus TB 07 ersetzt sie beim Zusammenführen), nie über schreibgeschützte Verbindungen (403), auf Produktion beim echten Lauf mit dem Namen der Verbindung. Jeder echte Import ein Eintrag `data.import` (Ziel, Zeilen, übersprungene, Statistik); eine SQL-Datei, die auf MySQL/MariaDB mittendrin scheitert, ebenfalls (mit `failed`).

**Statistik (E-06).** `Dialect::analyze`: `ANALYZE TABLE` bei MySQL/MariaDB (meldet Fehler als Ergebniszeile, deshalb gelesen und geprüft; aktualisiert auch die bis zu 24 Stunden alte Zahl in `information_schema` von MySQL 8), sonst `ANALYZE schema.tabelle`. Nach jedem CSV-Import automatisch (abwählbar), auf der Seite auf Knopfdruck mit Eintrag `data.analyze`.

**Aufträge und Warteschlange (B-07).** Tabelle `transfers` ([Datenmodell](../03-foundation/datenmodell.md) § 2.4, ULID, Frist 24 Stunden, täglicher `model:prune` löscht Auftrag und Ordner). `RunExport`/`RunImport` tragen **nur die Kennung**; der Worker liest Zugangsdaten aus der App-Datenbank. Im Hintergrund läuft ein Auftrag, wenn ein Worker konfiguriert ist (`QUEUE_CONNECTION` nicht `sync`, `PHPDBADMIN_TRANSFERS_BACKGROUND` nicht `false`), **sich in den letzten 90 Sekunden gemeldet hat** (`App\Support\QueueHeartbeat`, Lebenszeichen bei `JobPopping`/`JobProcessing`, höchstens alle 15 s in den Cache) **und** die Verbindung ihr Passwort speichert — «nur für diese Sitzung» kommt nie in die Warteschlange. Sonst läuft er in der Anfrage bis zu Grenzen (Vorgabe 100'000 Zeilen bzw. 20 MB) mit einer Meldung, die den Grund und den Ausweg nennt. Wer zuerst «wartet» auf «läuft» setzt, führt aus (bedingtes Update gegen Doppelläufe); `retry_after` der Warteschlange auf 3'660 s angehoben, weil ein Auftrag bis zu einer Stunde laufen darf. Fortschritt in `progress` und `rows`, höchstens zweimal je Sekunde geschrieben; dabei fragt der Lauf, ob abgebrochen wurde. Zeitlimit je Anweisung während eines Auftrags 600 s statt 15 s.

**Oberfläche.** Seite `workspace/transfers/Index` unter `c/{connection}/transfers` (Einstieg im Menü des Verbindungs-Chips): Exportieren, Importieren, Statistik, Aufträge mit Fortschritt, Abbrechen, Herunterladen bis zur Frist und Hinweis, wenn eine Minute lang kein Worker abholt. Die Oberfläche fragt den Stand alle 1,5 s über `transfers/status` ab (nur App-Datenbank) — und nur, solange ein Auftrag läuft. `?schema=…&table=…` belegt den Export vor (`transfersHref` in `resources/js/lib/transfers.ts`) — die Adresse für den Knopf «Exportieren» im Objektkopf. Seite `workspace/transfers/Import`: Schritte, Datei mit «anders lesen», Zuordnung mit Beispielwerten und Hinweis auf fehlende Pflichtspalten, Probelauf mit Fehlerzeilen, Import.

## Abnahmekriterien

| Anforderung | Kriterium (CR-001) | Nachweis |
|---|---|---|
| E-01 | Export und Re-Import ergeben dieselben Daten je Treiber | `RoundTripTest`: SQL (ganze Datenbank: Struktur und Daten → leere Datenbank) und CSV (Tabelle) je Treiber; Werte mit Umlauten, Emoji, `'`, `"`, `\`, `;`, Zeilenumbrüchen, `$$`, Text wie SQL, leerem Text gegen NULL, Nullbyte-Binärdaten, `0.30000000000000004`, `-1.0E-12`, Wahrheitswerten, JSON; Fremdschlüssel auf die eigene Tabelle, zusammengesetzter Schlüssel, Tabelle ohne Schlüssel, Sicht; nach dem Import zählt der Schlüssel weiter (PostgreSQL `setval`) |
| E-01 | 1 Mio. Zeilen unter 64 MB | `LargeTransferTest` (Gruppe `transfer-large`, nur mit `PROBE_GROSS=1`) — Zahlen unten |
| E-02 | Tests mit Fehlerzeilen; Probelauf schreibt nichts | `CsvImporterTest` je Treiber: vier Fehlerzeilen mit Nummer 5–8 (auch nach einem Feld über zwei Zeilen) und Grund, Tabelle danach leer; überspringen, nichts importieren, Werte wie SQL wörtlich; `SqlImporterTest`: Probelauf führt nichts aus, Fehler nennt Anweisung und Zeile, PostgreSQL/SQLite rollen zurück; `ImportControllerTest`: 403 für schreibgeschützte Verbindung und Lesemitglied, Name der Verbindung auf Produktion, Zuordnung mit `id; drop table …` abgewiesen |
| E-06 | Nach Import ist die Schätzung der Zeilenzahl aktuell | `StatisticsTest`: `Dialect::tables()` → `rows` = 500 ± 10 % auf MySQL, MariaDB, PostgreSQL; SQLite (keine Schätzung): `sqlite_stat1` = 500. Mutationsprobe ohne `ANALYZE`: MySQL (0), PostgreSQL (null), SQLite rot — **MariaDB bleibt grün**, InnoDB schätzt dort auch ohne |
| B-07 | Test beider Wege | `TransferRunnerTest`: mit Worker in die Warteschlange (Auftrag trägt nur die Kennung) und vom Job ausgeführt; ohne Worker in der Anfrage; Passwort nur in der Sitzung → in der Anfrage trotz Worker, Grenze mit Erklärung; Job ohne gespeichertes Passwort scheitert mit Erklärung; Abbrechen während des Lesens; `TransferControllerTest`: Grenze ohne Worker, Warteschlange mit Worker |

## Messwerte

Eine Million Zeilen (`id`, Zahl, Text, Dezimalzahl, Zeitpunkt), je Treiber in einem eigenen Prozess gemessen (`LargeTransferTest`, 29.10.2026, MacBook mit Herd: MySQL 8.4, MariaDB 10.11, PostgreSQL 18, SQLite 3.45). Speicher: Spitze des PHP-Speichers über dem Stand davor; Prozess: Zuwachs von `ru_maxrss` während des Schritts (sieht auch libpq, F-8).

| | MySQL | MariaDB | PostgreSQL | SQLite |
|---|---|---|---|---|
| Export CSV (49 MB) | 5,0 s · PHP 4,1 MB · Prozess +4,4 MB | 5,6 s · 4,1 MB · +4,5 MB | 4,9 s · 3,8 MB · +4,5 MB | 6,9 s · 3,6 MB · +5,8 MB |
| Export SQL, nur Daten (61 MB) | 3,2 s · 3,2 MB · +0 MB | 4,4 s · 3,2 MB · +0 MB | 3,5 s · 3,0 MB · +0,2 MB | 2,3 s · 2,8 MB · +0,1 MB |
| Import CSV (Pakete zu 1'000, mit ANALYZE) | 9,0 s · 2,0 MB | 6,1 s · 2,0 MB | 9,2 s · 2,3 MB | 3,6 s · 1,7 MB |
| Import SQL (10'000 INSERT zu 100 Zeilen) | 11,3 s · 2,1 MB | 5,9 s · 2,1 MB | 16,5 s · 2,1 MB | 2,8 s · 2,1 MB |

**Ergebnis:** Abnahme E-01 (unter 64 MB) mit Abstand erfüllt — höchstens 4,1 MB PHP-Speicher und 5,8 MB Prozesswachstum; der Prozess wächst auch bei PostgreSQL nicht, weil Pakete über den Schlüssel libpq nie das ganze Ergebnis halten lassen. Der erste gemessene Schritt je Prozess trägt das Laden der Klassen mit.

## Abweichungen von den Wireframes

| Bereich | Abweichung | Grund |
|---|---|---|
| 11 Export | eigene Seite je Verbindung statt Dialog über dem Raster; «gefilterte Zeilen» fehlt | der Knopf im Objektkopf und das Raster gehören dem Hauptstrang; er verlinkt mit `?schema=…&table=…` hierher |
| 11 Export | ohne «noch etwa 20 s» | Fortschritt in Prozent und Zeilen; eine Restzeit wäre bei geschätzten Zeilenzahlen geraten |
| 11 Import | Zuordnung nur nach gleichem Namen (nicht «Vorname» → `first_name`); kein Datumsformat «TT.MM.JJJJ» | Daten gehen wörtlich an die Datenbank; Formatumwandlung wäre eine eigene Anforderung |
| 11 Import | Fehlerzeilen nicht als CSV zum Herunterladen | offen, siehe unten |

## Tests

Neu: `RoundTripTest` (16), `CsvImporterTest` (14), `SqlImporterTest` (10), `StatisticsTest` (4), `TableReaderTest` (8), `TransferRunnerTest` (8), `TransferTest` (1, Frist), `SqlScriptTest` (9, Zerleger), `TransferControllerTest` (11), `ImportControllerTest` (9), `LargeTransferTest` (4, nur auf Wunsch). Je Treiber gegen die Herd-Dienste: MySQL 12, MariaDB 14, PostgreSQL 13, SQLite 13 Fälle plus die HTTP-Tests auf SQLite; zweites Konto für fremde Aufträge (404) und Lesemitglied (403).

**Stand der Suite:** 480 Tests, 464 bestanden, 16 übersprungen (die schweren Proben mit 1 Mio. Zeilen, bewusst nur auf Wunsch); Pint, Larastan Stufe 7, `vp check` und `vue-tsc` ohne Befund.

## Commits

`d466d6c` englische Zeilen des Rasters · `dd0dd7e` Scheibe 1: Server · `c7cf477` Scheibe 2: Oberfläche · Messtest und Protokoll (dieser Stand).

## Offen

- Export einer **Abfrage** (mit dem SQL-Editor) und der **gefilterten Zeilen** des Rasters (Knopf «Exportieren» im Objektkopf).
- Fehlerzeilen eines Imports als CSV zum Korrigieren herunterladen (Wireframe 11).
- CSV-Export ohne Schutz vor Formeln (`=`, `+`, `-`, `@` am Anfang öffnet Excel als Formel) — bewusst, weil er die Daten verändern würde; Option für TB 08 (Sicherheitsprüfung).
- MySQL mit `sql_mode` `ANSI_QUOTES` oder `NO_BACKSLASH_ESCAPES`: der Zerleger erkennt das nicht.

## Nach dem Zusammenführen

Sichtprüfung bei 1440 und 375 px (MariaDB, Umgebung Test): Knopf «Exportieren» im Objektkopf belegt Tabelle und Schema vor, CSV-Export von 240 Zeilen fertig mit Herunterladen, Abbrechen, Statistik aktualisieren mit Meldung. **Befund:** Laravels Vorgabe `QUEUE_CONNECTION=database` ohne laufenden Worker liess jeden Export für immer «wartet» stehen — `hasWorker()` sah nur die Konfiguration. Behoben mit dem Lebenszeichen des Workers (siehe oben, Test `test_queue_counts_as_worked_only_while_a_worker_reports_in`). Der gleiche deutsche Schlüssel «Aktualisieren» bedeutete zweierlei (Prozessliste: *Refresh*, Statistik: *Update*) — der Statistik-Knopf heisst jetzt «Statistik aktualisieren».

## Gelernt

- Laravel bindet Kommazahlen als Text mit 14 Stellen (`0.30000000000000004` → `0.3`), und `Connection::escape` tut dasselbe — für verlustfreie Werte `var_export` bzw. Werte aus der Datei als Text (Lektion im Vault).
- `Model::query()->value('status')` liefert mit Enum-Cast das Enum, nicht den Text; der Abbruch-Merker sah den Abbruch deshalb nie — erst ein Test mit echtem Abbruch zeigte es (`toBase()->value()`).
- MySQL 8 liefert Spalten aus `information_schema` in Grossbuchstaben (`ENGINE`) — immer mit Alias lesen.
- `pdf-bauen.py --help` kennt kein `--help` und baut alle sieben Dossiers neu (Lektion im Vault).
