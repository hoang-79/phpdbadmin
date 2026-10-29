# Timebox 10a — Objekte, Beziehungsdiagramm, EXPLAIN

**Stand:** 29.10.2026 · ✅ abgeschlossen — gebaut auf einem eigenen Zweig, zusammengeführt und im Browser geprüft.

## Ziel

Alles neben den Tabellen sehen und ändern — Sichten, Routinen, Trigger, Ereignisse, Sequenzen und Typen —, die Beziehungen eines Schemas auf einen Blick erfassen und verstehen, wie der Server eine Abfrage ausführt: auf vier Datenbanken mit derselben Bedienung und ohne dass etwas ungesehen passiert.

## Umfang

| Nr. | Anforderung (CR-001 § 4.5, § 4.6) | Prio | Ergebnis |
|---|---|---|---|
| S-06 | Views, Routinen, Trigger, Sequenzen, Typen und Enums (PostgreSQL), Events (MySQL/MariaDB) ansehen und bearbeiten | S | ✅ |
| S-07 | Beziehungsdiagramm aus den Fremdschlüsseln, als SVG exportierbar | S | ✅ (Durchlauf mit Basalto offen) |
| Q-06 | EXPLAIN als Baum mit Hinweisen | S | ✅ (Durchlauf im Browser offen) |

Kein Wireframe (Timebox-Plan: «—»); gestaltet im Stil der Wireframes 05 (Arbeitsbildschirm), 06 (SQL-Vorschau), 08 (Struktur) und 10 (SQL-Editor).

## Was entstanden ist

**Objekt-Dialekt (ADR-001).** Neben dem Dialekt-Vertrag, nicht in ihm: `App\Dialects\Objects\ObjectDialect` mit vier Umsetzungen (`MySqlObjects`, `MariaDbObjects`, `PostgresObjects`, `SqliteObjects`), geholt über `ObjectDialects::for()`. Er kennt die Arten je Treiber (`ObjectKind`: Sicht, Funktion, Prozedur, Trigger, Ereignis, Sequenz, Typ), liest die Definition (`SHOW CREATE …`, `pg_get_viewdef`/`pg_get_functiondef`/`pg_get_triggerdef`, Sequenzen und Typen zusammengesetzt, `sqlite_schema`), plant DROP mit je Segment gequoteten Namen, sagt, ob ersetzt werden kann (`CREATE OR REPLACE`), ob DDL in einer Transaktion läuft, und liest für das Diagramm Tabellen, Spalten und Fremdschlüssel eines Schemas mit zwei Abfragen. Der Vertrag selbst blieb unverändert — die Dialekt-Dateien bearbeiten parallel andere Timeboxes.

| Art | MySQL | MariaDB | PostgreSQL | SQLite |
|---|---|---|---|---|
| Sicht | ersetzen (OR REPLACE) | ersetzen | ersetzen; Spalten weg → löschen und neu, in einer Transaktion | löschen und neu, in einer Transaktion |
| Funktion, Prozedur | löschen und neu, nicht rückrollbar, bisherige Definition wird wiederhergestellt, wenn das Neuanlegen scheitert | ersetzen | ersetzen (Überladung über die Parameter unterschieden) | — |
| Trigger | wie Funktion | ersetzen | ersetzen (Trigger je Tabelle, über die Tabelle unterschieden) | löschen und neu, in einer Transaktion |
| Ereignis | wie Funktion | ersetzen | — | — |
| Sequenz | — | — (offen) | `ALTER SEQUENCE` | — |
| Typ (Aufzählung, zusammengesetzt, Domäne, Bereich) | — | — | `ALTER TYPE` bzw. `ALTER DOMAIN` | — |

**Ändern (S-06).** Die Detailseite zeigt die Definition (nur lesen, kopierbar, «Im Editor öffnen») und Angaben je Art (Parameter, Rückgabe, Tabelle, Zeitpunkt, Zeitplan, letzter Wert …). «Ändern» öffnet die Vorlage im SQL-Editor (CodeMirror, ⌘↵ = Vorschau); «Löschen …» geht direkt zur Vorschau. `DefinitionHead` liest den Kopf der eigenen Definition (Verb, Art, Schema, Name, bei Triggern die Tabelle; DEFINER, Anführungszeichen, Kommentare, Faltregeln je Treiber); `ObjectPlans` weist ab, was nicht passt — mehr als eine Anweisung, andere Art, anderes Schema, temporär, umbenannt, Trigger an einer anderen Tabelle, `OR REPLACE` auf etwas Bestehendes beim Anlegen —, bevor etwas läuft. Vor einer eigenen Definition wählt eine sichtbare erste Anweisung das Schema (`USE` bzw. `SET LOCAL search_path`). `ObjectChanges` plant auf der beschreibbaren Verbindung neu (`TargetConnections::withWritable`, Freigabe auf Zeit V-11 gilt) und schreibt genau einen Eintrag ins Protokoll — `object.create`, `object.alter`, `object.drop`, gelungen oder gescheitert, mit `restored` bei MySQL/MariaDB. Ausgeführt wird über `PDO::exec` (CREATE PROCEDURE geht in MySQL nicht vorbereitet, «?» im Rumpf einer PostgreSQL-Funktion ist kein Platzhalter).

**Navigator.** Gruppe «Typen» (PostgreSQL); Routinen, Trigger, Ereignisse, Sequenzen und Typen führen zur Detailseite; «+» je Gruppe zum Anlegen (nur mit «schreiben» und ohne Schreibschutz); Eintrag «Beziehungsdiagramm» unter dem Schema. Sichten haben im Objektkopf den Reiter «Definition».

**Beziehungsdiagramm (S-07).** `RelationDiagram` liest, `DiagramLayout` ordnet ohne Bibliothek: geschichtet nach Abhängigkeiten (verwiesene Tabellen links), Reihenfolge je Schicht nach dem Schwerpunkt der Nachbarn (vier Durchgänge), zu hohe Spalten brechen um, Tabellen ohne Beziehung gesammelt rechts, Kreise und Selbstbezüge gebrochen; Kästen zeigen höchstens 24 Spalten, Schlüssel immer. Im Browser (`RelationDiagram.vue`): Kasten ziehen, Fläche ziehen, Mausrad bzw. + und − zoomen, 0 einpassen, «Anordnen», Doppelklick öffnet die Struktur, Linien der Tabelle unter dem Zeiger treten hervor; Beziehungen zusätzlich als Liste. Über 60 Tabellen zuerst die 40 mit den meisten Beziehungen, weitere über «Tabellen wählen» (Filter, «Treffer wählen», «Nachbarn dazu», höchstens 200). `DiagramSvg` schreibt die Datei auf dem Server: eigenständig, Stile eingebettet in den hellen Farben der Gestaltung, Namen neu aus dem Katalog und für XML maskiert, an den Positionen aus dem Browser.

**EXPLAIN (Q-06).** «Erklären» (⌘E) im SQL-Editor für die Anweisung an der Schreibmarke bzw. die Auswahl; der Plan ersetzt die Ergebnisse, bis man ihn schliesst. `Explainer` wählt je Treiber das Format, `PostgresPlanReader`, `MySqlPlanReader` (beide JSON-Formen von MySQL und das von MariaDB) und `SqlitePlanReader` lesen in einen einheitlichen Baum (Operation, Objekt, geschätzte Zeilen, Kosten, Details; mit ANALYZE tatsächliche Zeilen, Durchgänge, Zeit). Hinweise: vollständiger Durchlauf (ab 1'000 Zeilen; SQLite schätzt nicht, dort jeder SCAN ohne Index), Filesort, temporäre Tabelle, Index in Frage aber nicht genutzt, Schätzung stark daneben (nur mit ANALYZE, nur an der tiefsten Stelle). Anklicken markiert den Knoten.

| Methode | Adresse (unter `c/{connection}`) | Name |
|---|---|---|
| GET | `s/{schema}/x/{kind}/{name}` | `workspace.definition` (`?qualifier=` Tabelle bzw. Parameter) |
| GET | `s/{schema}/x/{kind}` | `workspace.definition.create` |
| POST | `s/{schema}/x/{kind}/preview` | `workspace.definition.preview` (JSON) |
| POST | `s/{schema}/x/{kind}` | `workspace.definition.apply` |
| GET | `s/{schema}/diagram` | `workspace.diagram` (`?t[]=` Auswahl) |
| POST | `s/{schema}/diagram/svg` | `workspace.diagram.svg` (Datei) |
| POST | `sql/explain` | `workspace.sql.explain` (JSON) |

Seiten: `workspace/Definition` (Detail und Anlegen), `workspace/Diagram`; Komponenten `workspace/diagram/RelationDiagram`, `workspace/sql/ExplainPanel`.

## Abnahmekriterien

| Anforderung | Kriterium | Nachweis |
|---|---|---|
| S-06 | Test je Objektart und Treiber | 20 Fälle (MySQL 5, MariaDB 5, PostgreSQL 8, SQLite 2) je mit: über die Seite anlegen, im Katalog gelistet, Definition stimmt, Vorschau führt nichts aus, ohne «schreiben» (Mitglied mit Leserecht) und auf schreibgeschützter Verbindung 403 für Vorschau und Anwenden, Ändern über die Vorlage der Seite wirkt, Löschen, genau drei Einträge im Protokoll. Dazu: PostgreSQL rollt eine abgelehnte Ersetzung zurück, ersetzt ohne OR REPLACE in einer Transaktion, unterscheidet Überladungen; MySQL und MariaDB stellen die bisherige Routine wieder her, wenn das Neuanlegen scheitert; neun Definitionen, die nicht zur Seite passen, laufen nicht; Produktion erst mit dem Namen; Freigabe auf Zeit; 404 für unbekannte Arten, Objekte und fremde Verbindungen |
| S-07 | Durchlauf mit Basalto-Datenbank | Katalog-Endpunkt je Treiber (Tabellen ohne Sichten, PK und FK markiert, Beziehung, verwiesene Tabelle links); grosses Schema (65 Tabellen) zeigt 40 und folgt der Auswahl; SVG gültiges XML, Namen maskiert, Positionen übernommen, unbekannte Tabellen fehlen; Anordnung ohne Überlappung, mit Kreisen, Umbruch, gekürzten Kästen. **Durchlauf mit Basalto im Browser: Hauptstrang** |
| Q-06 | Durchlauf je Treiber | je Treiber: Baum mit Wurzel, Hinweis «vollständiger Durchlauf» für die Tabelle ohne passenden Index und keiner für die mit Index, Hinweis zeigt auf einen Knoten im Baum; EXPLAIN von DELETE/UPDATE ändert nichts, auch nicht über eine Funktion, die MariaDB beim Planen ausführt; ANALYZE misst auf den drei Server-Treibern; Abweisungen (ANALYZE einer Änderung, DROP, DELETE ohne WHERE, schon EXPLAIN, SELECT INTO); Plan-Leser mit echten Ausgaben aller Formate. **Durchlauf im Browser: Hauptstrang** |

Querschnitt: Rechte `view` zum Lesen, `write` zum Ändern; Schreibschutz (auch für den Besitzer) 403, ausser auf Zeit aufgehoben; Produktion mit Namenseingabe; Fehler des Zielservers als Meldung im Dialog bzw. am Feld (nie 500): «cannot change name of view column …», Syntaxfehler, «Der Server konnte die Anweisung nicht erklären: …».

## Gestaltungsentscheide (statt Wireframe-Abweichungen)

| Bereich | Entscheid | Grund |
|---|---|---|
| Gruppen im Navigator | jede Gruppe, die der Treiber kennt, auch leer — eingeklappt; «Typen» nur bei PostgreSQL, «Ereignisse» nur bei MySQL/MariaDB | O-04: was ein Treiber nicht kennt, fehlt bzw. steht mit Erklärung da; leere Gruppen tragen das «+» zum Anlegen |
| Ändern | Definition als Text im Editor statt Formular je Art | Routinen, Trigger und Typen haben je Treiber eigene Syntax; ein Formular für jede Kombination wäre grösser als der Nutzen. Prüfung des Kopfs verhindert Fehlgriffe |
| Vorlage | ohne DEFINER (MySQL/MariaDB), mit OR REPLACE wo möglich, sonst ALTER (Sequenzen, Typen) | Ändern soll ohne das Recht gehen, im Namen anderer anzulegen; ALTER erhält den Wert einer Sequenz |
| Bestätigung | derselbe Dialog wie in der Server-Verwaltung (`ConfirmChange`), Hinweise je Treiber («in einer Transaktion», «nicht rückrollbar …»), Name auf Produktion | eine Bestätigung für alle Änderungen |
| Löschen | nie CASCADE; PostgreSQL lehnt ab, wenn etwas daran hängt | wie TB 09 (DROP ROLE ohne DROP OWNED) |
| Diagramm | Anordnung auf dem Server, Zeichnen im Browser, Export auf dem Server | Anordnung und SVG mit PHPUnit prüfbar, ohne JavaScript-Testlauf; der Export liest Namen neu aus dem Katalog |
| EXPLAIN | Plan ersetzt die Ergebnisse statt eines dritten Bereichs | auf 375 px bleibt die Höhe für den Baum; `ResultPanel.vue` blieb unberührt (TB 10b) |
| Schmale Fenster | Angaben als umbrechende Liste, Definition umbricht statt zu scrollen, Werkzeugleisten brechen um | 375 px lesbar, keine Scrollbalken (Gestaltung § 8) |

## Entscheide in der Timebox

| Entscheid | Begründung |
|---|---|
| Objekt-Dialekt neben dem Vertrag (`ObjectDialects::for`) | Unterschiede je Treiber bleiben in der Dialekt-Schicht, ohne die Dateien zu berühren, die TB 05 und TB 11 parallel ändern |
| MySQL/MariaDB: nach gescheitertem Neuanlegen die bisherige Definition (ohne DEFINER) wieder anlegen | DDL dort ohne Transaktion — sonst wäre das Objekt nach einem Tippfehler weg (N-03) |
| Eine Definition = genau eine Anweisung, Kopf geprüft | sonst liesse sich über «Sicht ändern» beliebiges SQL anhängen, an der Vorschau vorbei |
| PostgreSQL-Routinen über `pg_get_function_identity_arguments`, Trigger über die Tabelle (`?qualifier=`) | Namen sind dort nicht eindeutig |
| EXPLAIN von Änderungen bei MySQL/MariaDB nur mit «schreiben» auf beschreibbarer Verbindung, in einer Transaktion, die immer zurückgerollt wird | beide lehnen es in der Nur-lesen-Transaktion ab (1792); ohne Wächter darf es nur, wer die Änderung auch ausführen dürfte — und MariaDB 10.11 führt beim Planen schreibende Funktionen aus (gemessen: zwei Zeilen geschrieben, MySQL 8.4 keine) |
| MySQL ab 8.3 mit `explain_json_format_version = 2`, ANALYZE als JSON erst ab da | das neue Format ist ein Baum mit tatsächlichen Werten; 8.0–8.2 zeigen das klassische Format ohne ANALYZE |
| Hinweis «Schätzung daneben» nur an der tiefsten Stelle | sonst erbt jeder Elternknoten denselben Fehler (PostgreSQL schätzt 200 Gruppen, Sort und Aggregate meldeten beide) |
| Protokoll: `object.*` in der Kategorie «Struktur» | im Protokoll filterbar wie die übrigen Strukturänderungen |

## Tests

100 neue Tests; Suite gesamt 938, davon 918 bestanden und 20 übersprungen — dieselben wie vor der Timebox (schwere Proben nur mit `PROBE_GROSS=1`, fehlende PHP-Erweiterungen, SSL-Probe); keiner der neuen Tests wird übersprungen. Testläufe mit `PHPDBADMIN_TEST_DATABASE=phpdbadmin_test_objekte`. `TargetDatabases::emptyDatabase` räumt jetzt auch Routinen, Ereignisse, Sequenzen und Typen weg (`dropOtherObjects`).

| Datei | Tests | MySQL | MariaDB | PostgreSQL | SQLite | ohne Datenbank |
|---|---|---|---|---|---|---|
| `Http/Controllers/Workspace/DefinitionControllerTest` | 39 | 6 | 6 | 11 | 16 | — |
| `Unit/Services/Objects/DefinitionHeadTest` | 20 | — | — | — | — | 20 |
| `Http/Controllers/Workspace/DiagramControllerTest` | 8 | 1 | 1 | 1 | 5 | — |
| `Unit/Services/Catalog/DiagramLayoutTest` | 6 | — | — | — | — | 6 |
| `Http/Controllers/Workspace/ExplainControllerTest` | 22 | 5 | 5 | 3 | 9 | — |
| `Unit/Services/Sql/Explain/PlanReaderTest` | 5 | — | — | — | — | 5 |
| **zusammen** | **100** | **12** | **12** | **15** | **30** | **31** |

Rechte mit zweitem Konto (Mitglied mit Leserecht) in jedem der 20 Lebenszyklus-Fälle und für EXPLAIN von Änderungen. Prüfkette vor jedem Commit: `vp check`, `vue-tsc`, Pint, Larastan, PHPUnit — grün; `vp build` ohne Fehler.

## Commits

`084934b` Scheibe 1: Objekte ansehen, anlegen, ändern und löschen (S-06) · `405bbd0` Scheibe 2: Beziehungsdiagramm mit SVG-Export (S-07) · `f6e3da4` Scheibe 3: EXPLAIN als Baum mit Hinweisen (Q-06) · `b44c022` EXPLAIN einer Änderung in zurückgerollter Transaktion (MariaDB) · Doku (dieser Commit).

## Offen

- **Browser-Durchlauf** bei 1440, 768 und 375 px, hell und dunkel: Detailseiten je Art, Anlegen, Ändern, Löschen, Diagramm mit der **Basalto-Datenbank** (Abnahme S-07), EXPLAIN je Treiber — Hauptstrang.
- **Sequenzen in MariaDB** (optional laut Auftrag) nicht gelistet; materialisierte Sichten (PostgreSQL) ebenso nicht.
- **MySQL 8.0–8.2:** klassisches JSON nur mit Probe-Ausgabe getestet (Herd hat 8.4), ANALYZE dort nicht angeboten.
- Das Wiederherstellen nach gescheitertem Neuanlegen kann selbst scheitern (z. B. fehlendes Recht) — dann Meldung und zurück zum Schema; nicht automatisch getestet.
- Ändern sich bei einer PostgreSQL-Routine die Parametertypen, entsteht eine zweite (Hinweis in der Vorschau, keine Sperre).
- Diagramm: Fremdschlüssel in andere Schemas fehlen; verschobene Kästen merkt sich die Seite nicht über das Neuladen hinaus.
- Befehlspalette und Tastenkürzel-Übersicht kennen Objekte, Diagramm und ⌘E noch nicht.
- Beim Zusammenführen: `routes/web.php`, `lang/en.json`, `resources/js/types/index.ts`, `tests/Concerns/TargetDatabases.php`, `Navigator.vue`, `ObjectHeader.vue` und der eine Link in `Schema.vue` berühren Dateien anderer Timeboxes.

## Nach dem Zusammenführen

Zusammengeführt mit `35c3654`. Durchlauf im Browser: Beziehungsdiagramm mit der Basalto-Testdatenbank (MariaDB `basalto_test`, 118 Tabellen, 215 Fremdschlüssel; gezeigt werden die 40 mit den meisten Beziehungen, 117 Linien, zoombar, Linien hinter den Kästen) — **Abnahme S-07 erfüllt**. Sicht `open_orders` auf PostgreSQL mit Definition, «Im Editor öffnen», Ändern, Löschen. EXPLAIN mit ⌘E im SQL-Editor auf PostgreSQL: Baum mit Kosten und Zeilen, «Mit ANALYZE messen» getrennt. Keine Verletzung der Content-Security-Policy durch die neuen Seiten.

## Gelernt

- MySQL und MariaDB lehnen `EXPLAIN` von INSERT/UPDATE/DELETE in einer Nur-lesen-Transaktion ab (1792), PostgreSQL nicht; MariaDB führt beim Planen sogar schreibende Funktionen aus — «EXPLAIN führt nichts aus» gilt nicht überall (Lektion im Vault).
- PHP: `max(...$liste)` mit Text-Schlüsseln wird zu benannten Argumenten — «does not accept unknown named parameters» (Lektion im Vault).
- `AssertableInertia::where()` gibt einem Closure bei Listen eine Collection — `(array)` liefert deren Innenleben, nicht die Werte (Lektion im Vault).
- SQLite: `notnull` ist ein Schlüsselwort — als Alias (`as notnull`) ein Syntaxfehler, der im Arbeitsbildschirm als «Verbindung gescheitert» erschien (Lektion im Vault).
