# Timebox 05 — Struktur ändern

**Stand:** 29.10.2026 · ✅ gebaut und getestet in drei Scheiben auf einem eigenen Zweig (Arbeitskopie), parallel zu TB 04 — Browser-Durchlauf und Zusammenführen im Hauptstrang offen.

## Ziel

Tabellen anlegen, ändern, umbenennen, kopieren, leeren und löschen sowie Datenbanken bzw. Schemas anlegen und löschen — auf allen vier Treibern mit einer Vorschau, die genau das zeigt, was danach läuft, und angewendet so, dass keine Daten verloren gehen (ADR-003, N-03).

## Umfang

| Nr. | Anforderung (CR-001 § 4.5, § 4.10) | Prio | Ergebnis |
|---|---|---|---|
| S-02 | Tabelle anlegen mit geführtem Formular (Spalten, Primärschlüssel, Indizes, Fremdschlüssel) und SQL-Vorschau | M | ✅ |
| S-03 | Tabelle ändern: Spalten hinzufügen, ändern, umbenennen, löschen; Indizes und Fremdschlüssel; Kommentare — je Treiber angewendet | M | ✅ |
| S-04 | Tabelle umbenennen, kopieren (Struktur, mit Daten), leeren, löschen — Leeren und Löschen nur mit Namenseingabe | M (Plan: S ↓) | ✅ |
| S-05 | Datenbank bzw. Schema anlegen und löschen | S | ✅ (SQLite: nur anlegen, siehe Entscheide) |
| N-03 | Kein Datenverlust durch die Anwendung: Vorschau, Transaktion wo möglich, DDL ohne Transaktion ehrlich angezeigt, SQLite-Umbau nie mit aktiven Fremdschlüsseln | M | ✅ für Strukturänderungen (Datenänderungen: TB 04) |

Wireframes 9 (Tabelle ändern, MySQL und PostgreSQL, Telefon) und 6 (SQL-Vorschau als Dialog).

## Was entstanden ist

**Planen (`app/Services/Schema`).** `SchemaPlans` baut aus geprüften Eingaben einen `SchemaPlan`: Aktion und Ziel fürs Protokoll, die Anweisungen, das Verfahren (`ApplyMode`), Hinweise, was verloren geht, und welchen Namen man zur Bestätigung eintippt. Anlegen und Ändern kommen aus Laravels `Blueprint::toSql()` — nie aus `pretend()` (F-5) —, umbenennen, kopieren, leeren, löschen und Schemas baut der Plan selbst, Namen je Segment gequotet (`SchemaName`). Jeder Name eines bestehenden Objekts muss im Katalog stehen. Vorschau und Anwenden planen über dieselbe Methode; die Vorschau über die Leseverbindung, das Anwenden noch einmal über die beschreibbare (`withWritable()`).

**Ändern in einer Reihenfolge für alle vier.** Neue Spalten (MySQL/MariaDB mit `AFTER`), Fremdschlüssel weg, Spalten ändern (unter altem Namen), neue Fremdschlüssel, umbenennen, Indizes weg, Spalten weg, neue Indizes, Tabellenkommentar. So baut SQLite die Tabelle höchstens einmal um, und zwar vor jedem Umbenennen.

**Anwenden je Treiber (`SchemaChanges`, ADR-003).**

| Treiber | Verfahren | Nach einem Fehler |
|---|---|---|
| MySQL, MariaDB | Anweisung für Anweisung, ohne Transaktion | angehalten; die Oberfläche zeigt je Anweisung «gelaufen», «gescheitert», «nicht gelaufen» |
| PostgreSQL | alles in einer Transaktion | nichts angewendet |
| SQLite | `PRAGMA foreign_keys = OFF` ausserhalb, Umbau in der Transaktion, `PRAGMA foreign_key_check` (nur neue Verletzungen zählen), Commit, `ON` — auch nach einem Fehler | nichts angewendet |

Beim SQLite-Umbau legt Laravel nur die Indizes neu an. Dazu kommen jetzt die Check-Constraints (in die neue Tabelle) und — nach sqlite.org — alle Sichten und Trigger der Datei: vorher gelöscht, danach mit ihrem gespeicherten Text neu angelegt. Ohne das scheitert das Umbenennen an jeder Sicht auf die Tabelle («error in view …: no such table»), und die Trigger der Tabelle gingen mit `DROP TABLE` verloren.

**Seiten und Dialoge.**

| Methode | Adresse (unter `c/{connection}`) | Name |
|---|---|---|
| GET | `s/{schema}/new-table` | `workspace.table.create` (Seite `workspace/TableCreate`) |
| POST | `s/{schema}/new-table/preview` | `workspace.table.preview` (JSON) |
| POST | `s/{schema}/new-table` | `workspace.table.store` |
| GET | `s/{schema}/o/{object}/alter` | `workspace.object.alter` (Seite `workspace/TableAlter`) |
| POST | `s/{schema}/o/{object}/alter/preview` | `workspace.object.alter.preview` (JSON) |
| POST | `s/{schema}/o/{object}/alter` | `workspace.object.alter.apply` |
| POST | `s/{schema}/o/{object}/actions/preview` | `workspace.object.actions.preview` (JSON) |
| POST | `s/{schema}/o/{object}/actions` | `workspace.object.actions.apply` (umbenennen, kopieren, leeren, löschen) |
| GET | `schemas/sqlite-folders` | `workspace.schemas.folders` (JSON, nur SQLite) |
| POST | `schemas/preview` | `workspace.schemas.preview` (JSON) |
| POST | `schemas` | `workspace.schemas.apply` |

Einstieg: «Tabelle ändern» und ein Menü «Weitere Aktionen» oben auf der Struktur-Seite, «Tabelle anlegen» und ein Menü für Datenbank bzw. Schema (SQLite: neue Datei) auf der Schema-Seite. Ohne Recht «schreiben» oder bei Schreibschutz bleiben die Knöpfe sichtbar, aber gesperrt, mit Grund daneben; die Seiten selbst antworten dann mit 403.

**Rechte und Protokoll.** Jede Änderung braucht die Policy-Fähigkeit `write` (Form-Request bzw. `Gate::authorize`), nie auf einer schreibgeschützten Verbindung (403 mit Klartext), eine fremde Verbindung ist 404; auf Produktion zusätzlich der Name der Verbindung (nur beim Anwenden). Leeren, Löschen und Spalten löschen verlangen den Namen der Tabelle bzw. des Schemas — geprüft in `SchemaChanges` vor der ersten Anweisung, ohne Eintrag im Protokoll. Jede angewendete Änderung ergibt genau einen Eintrag (`schema.create`, `schema.alter`, `schema.rename`, `schema.copy`, `schema.truncate`, `schema.drop`, `database.create`, `database.drop`), gelungen oder gescheitert, mit Verfahren, Zahl der gelaufenen Anweisungen und dem SQL über `SqlMask::mask()`; `AuditLog::record()` wird mit benannten Argumenten gerufen (passt zur neuen Signatur aus TB 07).

## Abnahmekriterien

| Anforderung | Kriterium (CR-001) | Nachweis |
|---|---|---|
| S-02 | Vorschau (aus `toSql()`) = ausgeführtes SQL; Tabelle danach per Introspektion gleich der Eingabe | `SchemaChangesTest` je Treiber: Mitschnitt der Anweisungen = Vorschau; Spalten, NULL, Typen je Treiber, Auto-Increment, Primärschlüssel, Index, eindeutiger Index, Fremdschlüssel mit `ON DELETE CASCADE`. Endpunkt je Treiber mit Weiterleitung, Meldung und einem Eintrag (Vorgabe im Protokoll maskiert) |
| S-03 | Tests je Treiber mit Elterntabelle und abhängigen Zeilen: Daten, Indizes, Fremdschlüssel und Kinder bleiben | `SchemaChangesTest` je Treiber: Spalte ändern, umbenennen, löschen, hinzufügen, Index anlegen an der Elterntabelle — 2 von 2 Kindern, Daten, eindeutiger Index und Fremdschlüssel der Kinder bleiben; SQLite: `foreign_key_check` leer, Fremdschlüssel wieder an; SQLite behält Checks, Trigger und Sichten. Rundlauf je Treiber: alle Typen der Liste auf ihre eigene Definition geändert — Typ, NULL und Vorgabe unverändert |
| N-03 | Test mit erzwungenem Fehler mitten in einer Folge | je Treiber: neue Spalte, dann eindeutiger Index auf doppelte Werte — MySQL/MariaDB: 1 Anweisung bleibt und wird gemeldet, PostgreSQL/SQLite: nichts angewendet; Kinder bleiben; ein Eintrag `failed`. Über den Endpunkt (MySQL): Meldung mit «gelaufen 1, gescheitert 2» |
| S-04 | ohne korrekten Namen keine Ausführung | je Treiber: Leeren ohne Namen und Löschen mit falscher Schreibweise abgewiesen, Zeilen bleiben, kein Eintrag; mit Namen geleert bzw. gelöscht. Dazu je Treiber: Umbenennen (Fremdschlüssel der Kinder folgen), Kopieren mit Daten (gleiche Spalten, eindeutiger Index, Zähler läuft in Original und Kopie weiter), Elterntabelle mit Kindern weder leeren noch löschen |
| S-05 | Test je Treiber (SQLite: neue Datei im erlaubten Ordner) | MySQL, MariaDB (Verbindung ohne festen Namen) und PostgreSQL (Schema): anlegen, Löschen ohne Namen abgewiesen, mit Namen gelöscht, zwei Einträge. SQLite: neue Datei mit SQLite-Kopf im erlaubten Ordner; Ordner ausserhalb, `../` im Namen, falsche Endung, bestehende Datei abgewiesen |

Querschnitt (`SchemaChangeRequestTest`, alle acht ändernden Endpunkte und beide Seiten): Recht «lesen» 403, schreibgeschützt 403 auch für den Besitzer, fremde Verbindung 404 — Datenbank und Protokoll unberührt; «schreiben» genügt; Produktion erst mit dem Namen der Verbindung, die Vorschau ohne. Namen, die Laravels Grammatik zerlegen würde (Punkt, « as », «->», «*», Anführungszeichen, Semikolon, über 63 Bytes), werden vor jeder Anweisung abgewiesen.

## Abweichungen von den Wireframes

| Wireframe | Abweichung | Grund |
|---|---|---|
| 9 Typ | Typ als Liste der Originalnamen des Treibers, Länge bzw. Genauigkeit in eigenen Feldern, «eigener Typ» als Freitext | eine Liste je Treiber plus freier Typ (CR S-02); Länge getrennt prüfbar |
| 9 Reihenfolge | keine Griffe zum Verschieben; neue Spalten kommen ans Ende (MySQL/MariaDB mit `AFTER`) | Umsortieren bestehender Spalten ist nicht in S-03 |
| 9 «+ Check» | fehlt; Checks bleiben beim SQLite-Umbau erhalten, werden aber nicht bearbeitet | nicht in S-03 (S-06 bzw. SQL-Editor) |
| 9 Reiter «Beziehungen» | fehlt | S-07 (TB 10); den Objektkopf bearbeitet der Hauptstrang |
| 9 Verlust je Anweisung | «Werte in ≈ N Zeilen» aus der Schätzung, ohne «davon M nicht NULL» | die Zählung bräuchte einen vollen Durchlauf grosser Tabellen |
| 9 Telefon | voller Entwurf als Karten statt nur Zusammenfassung, darüber der Hinweis «für den Desktop ausgelegt» | gleiche Seite für alle Breiten; Bearbeiten darf auf dem Telefon eingeschränkt sein (O-07) |
| 6 | Dialog für S-04 und S-05; «Tabelle ändern» zeigt die Vorschau rechts daneben (Wireframe 9) | Wireframe 9 hat Vorrang für das Ändern |

## Entscheide in der Timebox

| Entscheid | Begründung |
|---|---|
| Enge Namensregel (`SchemaName`): kein Punkt, keine Anführungszeichen, kein « as », «->», «*», «;», keine Steuerzeichen, höchstens 63 Bytes — auch für bestehende Namen, die ein Plan einsetzt | Laravels `wrap()` zerlegt an Punkten und « as », lässt «*» ungequotet und liest «->» als JSON-Pfad; PostgreSQL kürzt längere Bezeichner still. Was die Regel nicht erfüllt, geht im SQL-Editor |
| Vorgaben und `enum`-Werte über `PDO::quote` als `TrustedSql` | Laravel verdoppelt bei Vorgaben nur `'` (MySQL liest `\` als Escape) und setzt `enum`-Werte gar nicht in Anführungszeichen |
| Eigener Typ nur in einer engen Form (Wörter, eine Klammer mit Zahlen, `[]`); die bisherige Definition einer Spalte gilt wörtlich | freier Typ ohne Weg in fremdes SQL; bestehende Spalten bleiben änderbar |
| Ausdruck als Vorgabe: `CURRENT_TIMESTAMP` oder wörtlich die bisherige Vorgabe der Spalte | kein freies SQL in der Vorgabe |
| Bestehende Spalten: Typ aus der Liste nur, wenn Laravel ihn wieder genau so erzeugt (`ColumnTypes::specOf`), sonst wörtlich | eine Änderung von Kommentar oder NULL darf den Typ nicht umbauen |
| Auto-Increment und Primärschlüssel nur beim Anlegen | Umstellen eines Schlüssels ist je Treiber ein eigener Umbau, nicht in S-03 |
| Leeren und Löschen ohne CASCADE; PostgreSQL `TRUNCATE … RESTART IDENTITY` | eine Elterntabelle mit Kindern lehnt der Server ab (SQLite: `foreign_key_check`) — nie mitgelöschte Kinder; Zähler wie bei MySQL zurückgesetzt |
| Kopieren je Treiber: MySQL/MariaDB `CREATE TABLE … LIKE`; PostgreSQL `LIKE … INCLUDING ALL`, eigene Sequenzen für serial, `OVERRIDING SYSTEM VALUE`, `setval`; SQLite der gespeicherte CREATE-Text mit neuem Namen samt Indizes | gleiche Struktur, Zähler laufen weiter; Fremdschlüssel und Trigger werden nicht mitkopiert (steht im Hinweis) |
| S-05 MySQL/MariaDB nur über eine Verbindung ohne festen Datenbanknamen; PostgreSQL `DROP SCHEMA … CASCADE` mit Anzahl der Tabellen im Hinweis | eine neue Datenbank erschiene sonst nirgends; Löschen eines Schemas heisst «mit allem darin» wie bei MySQL |
| SQLite: neue Datei nur in einem erlaubten, beschreibbaren Ordner (Kopf per `VACUUM` geschrieben); Dateien löscht phpdbadmin nicht | eine SQLite-«Datenbank» ist die Datei — Löschen gehört ins Dateisystem, nicht in eine Web-Oberfläche |
| Eigenes Zeitlimit `PHPDBADMIN_SCHEMA_TIMEOUT_MS` (Vorgabe 10 Minuten) | MariaDBs `max_statement_time` gilt auch für `ALTER TABLE` und hätte grosse Umbauten nach 15 s abgebrochen |
| Recht «schreiben» (nicht «verwalten» wie in TB 09) | Struktur ändern ist Arbeit an den Daten der Verbindung, Server-Verwaltung nicht |
| Ein- und Mehrzahl als eigene Texte statt `trans_choice()` | deutsche Schlüssel ohne `de.json`: `trans_choice()` fällt auf die Rückfallsprache Englisch zurück |
| Feasibility-Probe `DdlPreviewProbeTest` entfernt | ihre Fälle (Vorschau = Ausführung, Kinder bleiben, Fehler mitten in der Folge) tragen jetzt `SchemaChangesTest`; die negativen Proben (`pretend()`, naive Transaktion) prüften Laravel und SQLite, nicht phpdbadmin — Befund bleibt in der Feasibility |

## Tests

126 neue Tests (davon 28 Einheitstests ohne Datenbank); die Probe mit 24 Fällen entfällt. Suite gesamt 713, davon 697 bestanden und 16 übersprungen (die schweren Proben auf Wunsch). Läufe mit `PHPDBADMIN_TEST_DATABASE=phpdbadmin_test_struktur`; Datenbanken aus S-05 heissen `phpdbadmin_s05_…` und werden im Test wieder entfernt (`finally`).

| Datei | Tests | MySQL | MariaDB | PostgreSQL | SQLite |
|---|---|---|---|---|---|
| `Services/Schema/SchemaChangesTest` | 17 | 4 | 4 | 4 | 5 |
| `Http/Controllers/Workspace/Schema/TableCreateControllerTest` | 15 | 1 | 1 | 1 | 12 |
| `Http/Controllers/Workspace/Schema/TableAlterControllerTest` | 7 | 1 | — | — | 6 |
| `Http/Controllers/Workspace/Schema/TableActionControllerTest` | 18 | 4 | 4 | 5 | 5 |
| `Http/Controllers/Workspace/Schema/SchemaControllerTest` | 11 | 1 | 2 | 2 | 6 |
| `Http/Requests/Workspace/Schema/SchemaChangeRequestTest` | 29 | — | — | — | 29 |
| `Http/Controllers/Workspace/WorkspaceControllerTest` (neu: `canWrite`) | 1 | — | — | — | 1 |
| `Unit/Services/Schema/ColumnTypesTest` | 28 | — | — | — | — |
| **zusammen** | **126** | **11** | **11** | **12** | **64** |

Prüfkette vor jedem Commit: `vp check`, `vue-tsc`, Pint, Larastan, PHPUnit — grün; `vp build` baut.

## Commits

`273eaa3` Scheibe 1: planen, je Treiber anwenden, Seiten · `3de69d9` Scheibe 2: Endpunkte je Treiber getestet, Rechte, Bestätigung · `d6f6d40` Zielspalte und Fehler der Vorschau · Doku (dieser Commit).

## Offen

- **Browser-Durchlauf** bei 1440, 768 und 375 px, hell und dunkel, gegen Wireframe 9 und 6 — Hauptstrang (in dieser Arbeitskopie ohne Browser gebaut).
- Spalten umsortieren, Primärschlüssel ändern, Checks bearbeiten, Index-Arten (Volltext, räumlich); zusammengesetzte Fremdschlüssel kann der Server, die Oberfläche bietet je Fremdschlüssel eine Spalte.
- SQLite-Tabellen mit Inline-`UNIQUE` (`sqlite_autoindex_…`): Laravels Umbau will den internen Index neu anlegen (vertauschte Argumente in `str_starts_with`) und scheitert — zurückgerollt, nichts verloren, aber nicht änderbar. Nicht getestet.
- `MaintenanceController` (TB 09) nutzt `trans_choice()` und zeigt auf Deutsch englische Mehrzahl — besser an einer Stelle lösen (Basalto: `SourceLocaleTranslator`, Lektion «Deutsche Mehrzahl fällt auf Englisch zurück» im Vault).
- Laravels `compileColumns` für MySQL setzt den Tabellennamen über `quoteString()` ohne Escape ein — die Strukturansicht (S-01) scheitert an Tabellennamen mit `'`. Vorbestehend, nicht in TB 05 behoben.

## Gelernt

- **Rundlauf je Treiber:** Eine Spalte auf ihre eigene Definition ändern und Typ, NULL und Vorgabe vergleichen fand sofort eine falsche Zuordnung (`tinyint(1)` wurde zu `tinyint`, weil die Ganzzahl-Regel vor dem Wahrheitswert griff). Jede Rückübersetzung aus der Introspektion braucht so einen Test.
- **SQLite prüft beim `RENAME TABLE` die ganze Datei:** Eine Sicht auf die umgebaute Tabelle liess den Umbau scheitern. Sichten und Trigger vor dem Umbau löschen und danach neu anlegen (sqlite.org, Abschnitt 7).
- **Laravels Grammatik ist kein Escaping:** `quoteString()` für `enum`-Werte verdoppelt nichts, Vorgaben nur `'` — Literale über `PDO::quote`, Bezeichner nur nach enger Regel.
- `trans_choice()` mit deutschen Schlüsseln ist eine bekannte Falle (Lektion im Vault) — und wurde trotzdem wieder gebaut, weil der Lektionen-Index nicht zu Beginn gelesen war; ein Endpunkt-Test mit der deutschen Meldung hat es gefunden.
