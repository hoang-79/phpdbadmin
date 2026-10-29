# Timebox 04 — Bearbeiten mit Vorschau und Rückgängig

**Stand:** 29.10.2026 · ✅ abgeschlossen — auf Hoangs Auftrag «mache alle TB durch» ohne Rückfragen gebaut. Q-07 (gefährliche Anweisungen) ist schon mit TB 03 gekommen.

## Ziel

Daten ändern, ohne Angst vor Datenverlust: alles zuerst sehen, in einer Transaktion anwenden, rückgängig machen können.

## Umfang

| Scheibe | Anforderungen | Ergebnis |
|---|---|---|
| 1 RowEditor | D-04, D-07, D-09, N-03 | ✅ Vorschau, eine Transaktion, Sicherung, Rückgängig |
| 2 Endpunkte | D-04, D-09, E-05, V-11 | ✅ inkl. Freigabe auf Zeit und Kopieren |
| 3 Oberfläche | D-04, D-05, E-05, V-11 | ✅ Wireframes 5, 6, 7 |

## Was entstanden ist

**RowEditor (ADR-003).** Gesammelte Änderungen — einfügen, ändern, löschen über den Primärschlüssel — zuerst als Vorschau aus `pretend()` mit eingesetzten Werten (nur zur Anzeige; ausgeführt wird mit Bindungen) und den Zeilen, die `ON DELETE CASCADE` mitlöschen würde. Anwenden auf **allen vier Treibern in einer Transaktion**: jede Änderung muss genau eine Zeile treffen, sonst wird alles zurückgerollt (`ChangeConflict`). Vorher- und Nachher-Werte in `change_snapshots` (bis 1'000 Zeilen, 7 Tage; Binärdaten als Base64), ins Protokoll nur SQL ohne Werte. **Rückgängig** kehrt rückwärts in einer Transaktion um — nur, wenn die Zeilen noch so aussehen wie nach der Änderung, sonst bleibt alles. Tabellen ohne Primärschlüssel nur lesen (Rückfall D-07), ungültiges JSON abgewiesen.

**Oberfläche.** Raster: Doppelklick, Eingabe oder F2 bearbeitet; geänderte Zellen, neue und zum Löschen markierte Zeilen farbig; Änderungsleiste; Vorschau als Seitenblatt mit Verfahren, nummerierten Anweisungen, Kaskaden-Hinweis und Namenseingabe in Produktion; danach «Zuletzt angewendet · Rückgängig machen». Zeilen-Detail mit Feld-Editor je Art (D-05): NULL-Haken (NULL ist nicht leer), Wahrheitswert, enum/set als Auswahl, JSON geprüft und formatierbar, lange Texte im Textfeld; Duplizieren, Löschmarke. **Kopieren (E-05)** als TSV, Markdown oder INSERT — Zeile oder Ausschnitt, auf dem Server erzeugt (Anführungszeichen je Treiber).

**Schreibschutz auf Zeit (V-11).** Für 15 Minuten, nur mit Recht «schreiben», mit Pflichtgrund, Grund und Ende im Protokoll; im Kopf «Schreiben erlaubt bis … · wieder sperren». Die Freigabe liegt in der Sitzung, `TargetConnections` berücksichtigt sie überall (Bearbeiten, SQL-Editor, Struktur, Server).

## Abnahmekriterien

| Anforderung | Kriterium (CR-001) | Nachweis |
|---|---|---|
| D-04 | je Treiber: nichts geschrieben vor «Anwenden»; Fehler in der zweiten Anweisung rollt die erste zurück; Vorschau aus `pretend()` mit Bindungen; Kaskaden gezählt | `RowEditorTest` (vier Treiber): Vorschau ändert nichts, zählt 2 Kinder; doppelter Name in der zweiten Anweisung → erste zurückgerollt, kein Protokolleintrag |
| N-03 | erzwungener Fehler mitten in einer Folge; Eltern mit Kindern | obiger Fall; verfehlte Zeile (Schlüssel 999) → nichts geändert; Kaskade löscht genau die Kinder des gelöschten Kunden |
| D-05 ↓ | je Typ und Treiber byte-genau | Umlaute, Emoji, Anführungszeichen, Backslash, leer gegen NULL, Dezimal, Datum, Wahrheitswert, JSON auf vier Treibern; Binärdaten nur NULL (Rückfall: Bildvorschau später) |
| D-07 ↓ | mehrdeutige Zeile wird nicht geändert | Tabelle ohne Schlüssel mit zwei gleichen Zeilen: abgewiesen, beide unverändert (Rückfall: nur lesen) |
| D-09 | anwenden, rückgängig, Zustand gleich | vier Treiber: Ändern + Einfügen + Löschen, dann Rückgängig → Tabellen gleich dem Ausgangszustand; zweites Rückgängig abgewiesen; spätere fremde Änderung → Konflikt, nichts überschrieben |
| E-05 | Test | `RowCopyTest` (Sonderzeichen, NULL, Binärdaten, Anführungszeichen je Treiber), `RowChangeControllerTest` (Zeile, Ausschnitt, Filter) |
| V-11 | Ablauf nach 15 Minuten (Uhr gestellt), Grund und Ende im Protokoll, ohne Recht 403 | `RowChangeControllerTest`: freigegeben → änderbar; nach 16 Minuten 403; Protokoll mit Grund und Ende; Leser 403; Sperren protokolliert |
| Q-07 | — | mit TB 03 |

Durchlauf im Browser (MariaDB, Umgebung Test): Zelle ändern, Wahrheitswert umschalten, Vorschau, Anwenden, Rückgängig — Ausgangszustand wieder da.

## Abweichungen von den Wireframes

| Bereich | Abweichung | Grund |
|---|---|---|
| 7 Detail | Binärdaten: keine Vorschau, kein Herunterladen, kein Ersetzen | Rückfall D-05: Werte als Text, Bildvorschau später |
| 5 Kopf | «Exportieren» fehlt | TB 06 |
| 6 Vorschau | Hinweis «nicht in einer Transaktion» bei MyISAM fehlt | alle Testtabellen InnoDB; MyISAM ist selten und kennt keine Transaktion — als offene Lücke vermerkt |

## Tests

`RowEditorTest` 32 (vier Treiber), `RowChangeControllerTest` 8, `RowCopyTest` 3. Suite nach TB 04: 744 Tests, 728 bestanden, 16 übersprungen.

## Commits

`7a82450` RowEditor · `fcf1d18` Endpunkte · `1066404` Oberfläche.

## Gelernt

- `Model::create()` hat die Spaltenvorgaben der Datenbank nicht — eine Zeitzone fehlte (`setTimezone(null)`). Vorgaben gehören auch ins Modell (`$attributes`).
- MySQL quotet in `substituteBindingsIntoRawSql()` mit Backslash, die anderen mit verdoppeltem Hochkomma — Tests auf beides auslegen.
