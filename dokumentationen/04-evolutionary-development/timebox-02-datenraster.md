# Timebox 02 — Datenraster und Filter

**Stand:** 29.10.2026 · ✅ abgeschlossen — auf Hoangs Auftrag «mache alle TB durch» ohne Rückfragen gebaut.

## Ziel

Die Daten jeder Tabelle und Sicht schnell und in allen vier Datenbanken gleich sehen: blättern, sortieren, filtern, suchen, zählen, Fremdschlüsseln folgen — auch bei einer Million Zeilen.

## Umfang

| Scheibe | Anforderungen | Ergebnis |
|---|---|---|
| 1 Datendienst | D-01, D-02, D-03, D-06 | ✅ `TableData`, `RowDetail`, `Cells` — 52 Tests über vier Treiber |
| 2 Raster und Zeilen-Detail | D-01–D-03, D-06, O-09 | ✅ Wireframes 05 und 07 (Detail lesend; Bearbeiten mit TB 04) |
| 3 Reiter und Kürzel | O-03, O-05 | ✅ |
| Messung | N-01 | ✅ unter 20 ms für die erste Seite |

## Was entstanden ist

**Datendienst.** Blättern über den Primärschlüssel (zusammengesetzt ausgeschrieben, weil nicht jede Version Zeilenwerte vergleicht), sonst über den Versatz; der Primärschlüssel ist immer letztes Sortierkriterium. **NULL steht in beiden Richtungen zuletzt** — ausdrücklich über `case when … is null`, nicht nach Gewohnheit des Treibers. Geführte Filter (=, ≠, <, ≤, >, ≥, enthält, enthält nicht, beginnt, endet, ist NULL, ist nicht NULL) mit und/oder; alle Werte gebunden, `%` und `_` im Wert als Zeichen (Escape `!`). «enthält» ohne Gross-/Kleinschreibung: PostgreSQL `ILIKE` auf den Text der Spalte, MySQL/MariaDB nach Kollation (meist auch ohne Akzente), SQLite über eine Unicode-Faltfunktion (`phpdbadmin_fold`), die die `ConnectionFactory` registriert — sonst fände «MÜLLER» nicht «Müller». Freies WHERE als Klammerausdruck im Schreibschutz-Wächter mit Zeitlimit; Suche über alle Textspalten. Zählen genau bis `PHPDBADMIN_EXACT_COUNT_LIMIT` (50'000), darüber die Schätzung des Servers, gefiltert auf grossen Tabellen offen mit «genau zählen». Werte gleich in allen vier: Wahrheitswerte `true`/`false` (MySQL liefert 1/0), Binärdaten als Grösse und Anfang, lange Texte gekürzt, Ganzzahlen über 2^53 als Text, PostgreSQL-`bytea` aus dem Strom gelesen. Fremdschlüssel bekommen die Beschriftung der Zielzeile (bevorzugt `name`, `title`, `email` …). `TrustedSql` kennzeichnet die zwei Stellen mit absichtlich rohem SQL.

**Raster** (`/c/{verbindung}/s/{schema}/o/{objekt}`). Erste Seite kommt mit der Seite, weitere beim Scrollen (`rows`), dazu `count`, `row` und `view`. Virtualisiert (TanStack Virtual), Sortieren per Klick (Umschalt fügt hinzu), Breite ziehen, Spalten ausblenden — beides je Konto gemerkt (`table_views`). Tastatur: Pfeile, Pos1/Ende, Bild auf/ab, Eingabe öffnet die Zeile. Filterleiste mit Chips, freiem WHERE, Suche und Zeilenzahl. Zeilen-Detail als Seitenblatt mit «verweist auf … öffnen» und «wird verwiesen von … 3 Zeilen zeigen». Telefon: Karten mit «alle Felder».

**Zustände (O-09).** Leere Tabelle, kein Treffer mit «Filter zurücksetzen», abgelehntes WHERE mit Meldung des Servers, Zeitlimit, abgerissene Verbindung und abgelaufene Sitzung beim Nachladen — je mit Ursache und nächstem Schritt.

**Reiter (O-03).** Jede geöffnete Tabelle oder Sicht wird ein Reiter mit ihrer letzten Adresse samt Filtern; gespeichert im Browser je Verbindung, übersteht das Neuladen. **Kürzel (O-05):** Übersicht über «?» oder das Tastatur-Symbol.

## Abnahmekriterien

| Anforderung | Kriterium (CR-001) | Nachweis |
|---|---|---|
| D-01 | Tests je Treiber mit Sortierung inkl. NULL, Reihenfolge ausdrücklich | `TableDataTest`: NULL zuletzt in beiden Richtungen, absteigend = Umkehrung von aufsteigend, Blättern ohne Lücken und Doppel (einfacher und zusammengesetzter Schlüssel, ohne Schlüssel), stabiler Versatz |
| D-02 | je Treiber, mit Umlauten und Werten, die wie SQL aussehen | `x' or '1'='1`, `'; drop table people; --`, `%`, `_`, «müller»/«MÜLLER»/«jürg», und/oder, NULL, freies WHERE inkl. `1 = 1; delete from people` (nichts gelöscht) |
| D-03 | Test je Treiber | Beschriftung des Fremdschlüssels, verweisende Zeilen gezählt, Zeile mit Verweisen in beide Richtungen |
| D-06 | Test mit Schwelle | geschätzt über der Grenze, genau darunter, gefiltert offen, «genau zählen»; SQLite zählt mangels Schätzung immer genau |
| N-01 | Messung mit erzeugten Daten je Treiber | `GridPerformanceTest` (`PHPDBADMIN_LEISTUNG=1`), Zahlen unten |
| O-03 | Durchlauf | im Browser: zwei Reiter, Neuladen, beide da |
| O-05 | Durchlauf nur mit Tastatur | Raster mit Pfeilen, Eingabe, Escape; Kürzel-Dialog über das Symbol. Die Taste «?» selbst liess sich mit dem Browser-Werkzeug nicht auslösen (es erzeugt kein `keydown`) — **von Hand offen** |
| O-09 | leere Tabelle, SQL-Fehler, getrennte Verbindung, abgelaufene Sitzung | `ObjectControllerTest` (ungültiges WHERE → Fehlerkarte, 401/404/422/503 der JSON-Antworten), Oberfläche erklärt jede Ursache |

### N-01: erste Seite einer Tabelle mit einer Million Zeilen (lokal, Herd)

| Fall | MySQL 8.4 | MariaDB 10.11 | PostgreSQL 18 | SQLite |
|---|---|---|---|---|
| ohne Sortierung | 6,5 ms | 6 ms | 17,1 ms | 6,6 ms |
| `created_at` absteigend (Index) | 1,5 ms | 2,3 ms | 2,4 ms | 5,9 ms |
| Filter `category = 42` (ohne Index) | 4,5 ms | 4,5 ms | 20,3 ms | 35,5 ms |
| Suche über Textspalten | 188,5 ms | 138 ms | 75,4 ms | 156,7 ms |

Zeit im Dienst inklusive Spalten, Zählung bzw. Schätzung und Beschriftungen.

## Abweichungen von den Wireframes

| Bereich | Abweichung | Grund |
|---|---|---|
| 05 Kopf | «Zeile einfügen», «Exportieren», Änderungsleiste, «Rückgängig machen» fehlen | Bearbeiten TB 04, Export TB 06 |
| 05 Navigator | «Gespeichert» und «Verlauf» fehlen | SQL-Editor TB 03 |
| 07 Detail | lesend; «Duplizieren», «Zum Löschen markieren», «Zeile kopieren als» fehlen | TB 04 (D-04, E-05) |
| 05 Raster | Spaltenkopf mit Typ statt Kurzform «decimal(10,2)» gekürzt | Breite der Spalte; der volle Typ steht in der Struktur |

## Tests

`TableDataTest` (52 = 13 × 4 Treiber), `ObjectControllerTest` (13), `GridPerformanceTest` (4, nur auf Wunsch). Suite: 393 Tests, 381 bestanden, 12 übersprungen (schwere Proben).

## Commits

`88a95d7` Datendienst · `b2842e7` Raster und Detail · `88dec69` Reiter und Kürzel · `8af0231` Messung N-01. Die Raster-Probe der Feasibility (`/probe/raster`) ist entfernt.

## Gelernt

- `rsync -R --delete` löscht auch in den Elternordnern des relativen Pfads — im Prüf-Workspace verschwanden so `database/database.sqlite`, Factories und Seeder (nur dort; Lektion im Vault).
- Larastan verlangt für rohes SQL ein Literal; statt die Prüfung zu umgehen, markiert `TrustedSql` die bewussten Stellen sichtbar.
- PostgreSQL schneidet als Text gebundene Binärdaten am Nullbyte ab — Testdaten für `bytea` als Literal setzen.
