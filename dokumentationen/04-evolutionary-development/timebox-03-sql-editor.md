# Timebox 03 — SQL-Editor und Befehlspalette → Alpha

**Stand:** 29.10.2026 · ✅ abgeschlossen — auf Hoangs Auftrag «mache alle TB durch» ohne Rückfragen gebaut. Q-07 (gefährliche Anweisungen, eigentlich TB 04) ist mitgekommen, weil der Editor es braucht.

## Ziel

SQL schreiben und ausführen wie in einem Desktop-Werkzeug — mit Grenzen, die eine Datenbank in Produktion schützen, und Abbrechen, das wirklich abbricht.

## Umfang

| Scheibe | Anforderungen | Ergebnis |
|---|---|---|
| 1 Zerlegen, einordnen, ausführen | Q-02, Q-03, Q-07, Q-09 | ✅ `StatementSplitter`, `StatementClassifier`, `QueryRunner`, Arbeitsprozess für SQLite |
| 2 Editor, Verlauf, Palette | Q-01, Q-04, O-02 | ✅ Wireframes 10 und 12 |

## Was entstanden ist

**Zerleger.** Eine Anweisung je Aufruf (die Verbindungen nehmen nie mehrere an, F-7). Kennt Zeichenketten mit den Escapes des Treibers (Backslash bei MySQL/MariaDB, `E'…'` bei PostgreSQL), Bezeichner (`"…"`, `` `…` ``, `[…]` bei SQLite), Kommentare (`--`, `#` bei MySQL, verschachtelte Blockkommentare bei PostgreSQL), Dollar-Blöcke, `BEGIN … END` in Routinen und Triggern (`END IF/LOOP/WHILE/REPEAT/CASE` richtig gezählt) und `DELIMITER` aus MySQL-Dumps; findet die Anweisung an der Schreibmarke (Byte-Position, der Browser rechnet UTF-16 in UTF-8 um).

**Einordnen.** Lesend (SELECT ohne INTO, SHOW, EXPLAIN ohne ANALYZE einer Änderung, WITH ohne ändernde Teile, lesendes PRAGMA …) oder schreibend; gefährlich sind DROP, TRUNCATE, DELETE und UPDATE ohne WHERE auf oberster Ebene und ALTER … DROP — Kommentare, Zeichenketten und Unterabfragen zählen nicht.

**Ausführen.** Lesendes immer im Schreibschutz-Wächter, Schreibendes nur mit Recht «schreiben» auf beschreibbaren Verbindungen und mit Protokolleintrag ohne Werte. Zeitgrenze `PHPDBADMIN_EDITOR_TIMEOUT` (30 s), Zeilen je Ergebnis `PHPDBADMIN_EDITOR_ROWS` (1'000, «mehr laden» bis 10'000). MySQL/MariaDB lesen ungepuffert und verwerfen den Rest mit `KILL QUERY`, PostgreSQL liest über einen Server-Cursor — nie liegt ein ganzes grosses Ergebnis im Speicher. **Abbrechen** ist eine zweite Anfrage: Ein Merker im Cache entscheidet über «abgebrochen» (MySQL kehrt nach `KILL QUERY` auch ohne Fehler zurück, F-2), die laufende Anweisung wird über eine eigene Verbindung beendet. **SQLite (Q-09)** läuft in einem Arbeitsprozess (`phpdbadmin:sqlite-worker`), den Zeitgrenze oder Abbruch beenden; fertige Ergebnisse davor bleiben.

**Editor (Wireframe 10).** CodeMirror 6 mit dem Dialekt des Treibers, Vervollständigung aus dem Schema (eine Abfrage je Treiber, als Deferred-Prop nachgeladen), Hervorhebung mit den Farben des Stylesheets, Formatieren ohne neue Abhängigkeit. ⌘↵ Anweisung an der Schreibmarke oder Auswahl, ⇧⌘↵ alles. Ergebnisse als Reiter im virtualisierten Raster, als TSV kopieren. Gefährliches fragt nach, in Produktion mit dem Namen der Verbindung. Abfragen sind Reiter wie Tabellen, ihr Text bleibt im Browser; der Objektkopf hat den Reiter «SQL» mit einer Vorlage.

**Verlauf und gespeicherte Abfragen (Q-04).** `query_history` je Konto und Verbindung mit Suche (Frist 90 Tage, täglicher `model:prune`), `saved_queries` mit Namen.

**Befehlspalette (O-02, Wireframe 12).** ⌘K oder Suchfeld im Kopf: Objekte der offenen Verbindung, zuletzt Geöffnetes, andere Verbindungen (Suchquelle `/palette`, nur sichtbare), Aktionen («Neue Abfrage auf …», «Struktur von …», Server, Tastenkürzel); «>» zeigt nur Aktionen; Pfeile, Eingabe, Escape.

## Abnahmekriterien

| Anforderung | Kriterium (CR-001) | Nachweis |
|---|---|---|
| Q-01 | Durchlauf je Treiber | im Browser mit PostgreSQL: Vorlage, Formatieren, Ausführen, Vervollständigung (Endpunkt-Test: Spalten der Tabellen); Hervorhebung je Dialekt über `@codemirror/lang-sql` |
| Q-02 | Abbrechen beendet die Abfrage auf dem Server; «abgebrochen» auch bei MySQL ohne Fehler | `QueryRunnerTest`: zweiter Prozess mit `sleep(20)`/`pg_sleep(20)` endet nach `KILL QUERY`/`pg_cancel_backend` in unter 5 s (MySQL, MariaDB, PostgreSQL); Merker entscheidet über «abgebrochen» (vier Treiber) |
| Q-03 | lange und schreibende Anweisung auf geschützter Verbindung je Treiber | Zeitgrenze 1 s auf vier Treibern (MySQL mit grossem Kreuzprodukt, weil `SLEEP` still abbricht); Löschen auf schreibgeschützter Verbindung abgewiesen; ohne Recht nicht ausgeführt |
| Q-04 | Test | `SqlControllerTest`: Verlauf nur des eigenen Kontos, Suche, gespeicherte Abfragen fremd 404 |
| Q-07 | Varianten mit Kommentaren, Gross-/Kleinschreibung, mehreren Anweisungen | `StatementClassifierTest` (13 Fälle Gefahr, 15 Fälle Art), `SqlControllerTest`: ohne Bestätigung 409 und nichts geändert, Produktion mit falschem Namen 409 |
| Q-09 | Abfrage über der Grenze beendet, Oberfläche meldet es | SQLite-Arbeitsprozess mit Zeitgrenze 1 s und mit Abbruch-Merker |
| O-02 | Test der Suchquelle; Durchlauf nur mit Tastatur | `PaletteControllerTest` (nur sichtbare Verbindungen, keine Geheimnisse); im Browser ⌘K, Tippen, Auswahl |

Alpha-Kriterium «Hoang nutzt phpdbadmin eine Woche zum Nachsehen»: **offen (Hoang)**.

## Abweichungen von den Wireframes

| Bereich | Abweichung | Grund |
|---|---|---|
| 10 Werkzeugleiste | Auswahl des Schemas als Liste (bei mehreren) | Wireframe zeigt eine Verbindung mit einer Datenbank |
| 10 Ergebnis | «Ergebnis exportieren» fehlt | Export TB 06 |
| 12 Palette | «Andere Verbindungen» zeigt Verbindungen, nicht ihre Tabellen | Tabellen anderer Verbindungen hiessen, jede zu öffnen — Suchquelle bewusst schlank |

## Tests

`StatementSplitterTest` 22, `StatementClassifierTest` 28, `QueryRunnerTest` 28 (vier Treiber), `SqlControllerTest` 8, `PaletteControllerTest` 2. Suite nach TB 03: 611 Tests, 595 bestanden, 16 übersprungen (schwere Messungen).

## Commits

`efde8fe` Zerlegen, einordnen, ausführen · `d91231a` Editor, Verlauf, Palette. Die Editor-Probe der Feasibility ist entfernt.

## Gelernt

- Ein eigener Hilfsname `run()` in einem PHPUnit-Test überschreibt die finale Methode der Testklasse — Fatal Error beim Laden.
- PostgreSQL: Nach einem Fehler im Cursor ist die Transaktion abgebrochen; ein `close` im `finally` wirft dann einen neuen Fehler und überdeckt den eigentlichen (Zeitlimit wurde zu «Fehler»).
- Unter PHP-FPM zeigt `PHP_BINARY` auf FPM — für einen Arbeitsprozess die CLI suchen (Einstellung `PHPDBADMIN_PHP_BINARY`).
- Der eingebaute Browser ist zwischen parallelen Helfern geteilt; Durchläufe macht nur der Hauptstrang.
