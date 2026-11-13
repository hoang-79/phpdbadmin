# Timebox 13 — Operationen für Datenbank und Tabelle

**Stand:** 13.11.2026 · ✅ gebaut — Wunsch von Hoang: «ich vermisse etliche datenbank funktionen. Wie zum beispiel, db dump, dbbackup, db optimierung oder repair. im phpmyadmin hat es auch für tabellen analyise, optomierung etc. bitte analysiere was sonst noch an funktionen fehlen, und integriere es.»

## Ziel

Was man aus phpMyAdmin für Datenbank und Tabelle kennt — Dump, Sicherung, Analysieren, Optimieren, Reparieren, Optionen — dort finden, wo man es sucht, und die echten Lücken schliessen.

## Analyse

Feinabgleich im [Funktionsraster § 10](../00-change_request/funktionsraster.md#10-feinabgleich-operationen-für-datenbank-und-tabelle-13112026): Dump (Export), Sicherung und Wiederherstellung (E-03), Analysieren, Optimieren und Prüfen (W-05) **gab es schon** — aber nur über das Menü des Verbindungs-Chips («Export und Import», «Server → Wartung»). Wirklich fehlten Reparieren, Prüfsumme, Indizes neu aufbauen, Tabellenoptionen, Verschieben in eine andere Datenbank und der Zeichensatz einer Datenbank. Neu im [CR-001](../00-change_request/CR-001-phpdbadmin.md) (Fassung 6): O-10, W-06, S-10, S-11, S-12.

## Umfang

| Anforderung | Plan | Ergebnis |
|---|---|---|
| O-10 Export, Import, Sicherung und Wartung aus den Menüs von Datenbank und Tabelle, vorgewählt | Must | ✅ |
| W-06 Reparieren, Prüfsumme, Indizes neu aufbauen | Should | ✅ |
| S-10 Tabellenoptionen: Engine, Zeichensatz und Sortierung, nächster Wert des Zählers | Should | ✅ |
| S-11 Tabelle in eine andere Datenbank bzw. ein anderes Schema verschieben oder kopieren | Should | ✅ |
| S-12 Zeichensatz und Sortierung einer Datenbank | Could | ✅ |

## Was entstanden ist

**Menüs (O-10).** «Datenbank ▾» und «Weitere Aktionen» einer Tabelle führen zu Exportieren (die ganze Datenbank als SQL bzw. die Tabelle), Importieren, Sichern und wiederherstellen und einem Untermenü **Wartung** mit den Arten des Treibers (`workspace.connection.maintenance`). Die Seiten übernehmen die Vorauswahl aus der Adresse (`?operation=&schema=&table=`, `?scope=schema`, Sprungmarken `#exportieren`, `#importieren`). Die Menüs sind nicht mehr ganz gesperrt, wenn das Konto nicht schreiben darf — gesperrt bleiben nur die Strukturänderungen; die Seiten prüfen selbst. Das Untermenü sitzt in einem eigenen Portal, sonst schnitt das Hauptmenü es ab.

**Wartung (W-06).** Neue Arten in `MaintenanceOperation`: `REPAIR TABLE` und `CHECKSUM TABLE` (MySQL, MariaDB), `REINDEX` (PostgreSQL je Tabelle, SQLite je Tabelle — auch für das ganze Schema, weil SQLite kein «REINDEX schema» kennt). InnoDB kann nicht reparieren und meldet das als Hinweis, nicht als Fehler; die Prüfsumme steht als Zahl im Ergebnis.

**Verschieben und Kopieren (S-11).** Umbenennen und Kopieren nehmen ein Ziel (`to_schema`): MySQL/MariaDB `RENAME TABLE a.t TO b.n` bzw. `CREATE TABLE b.n LIKE a.t` + `INSERT`; PostgreSQL `ALTER TABLE … SET SCHEMA` (Indizes, Constraints und eigene Sequenzen gehen mit), danach umbenennen; die Kopie legt ihre Sequenzen im Ziel an. SQLite kopiert in angehängte Dateien, verschiebt aber nicht. Protokoll `schema.move`.

**Tabellenoptionen (S-10).** Dialog «Tabellenoptionen …» (MySQL, MariaDB): Engine aus `SHOW ENGINES` (nur verfügbare; ohne PERFORMANCE_SCHEMA, SEQUENCE, MRG_MyISAM, FEDERATED und BLACKHOLE, das jede Zeile verwirft), Sortierung nach Zeichensatz gruppiert — als Vorgabe (`DEFAULT CHARACTER SET`) oder mit Umwandeln (`CONVERT TO`) —, nächster Wert des Zählers. Nur, was sich ändert, wird eine Anweisung; sonst «Nichts geändert».

**Zeichensatz der Datenbank (S-12).** Dialog «Zeichensatz und Sortierung …» im Menü Datenbank: `ALTER DATABASE … CHARACTER SET … COLLATE …`, auf Wunsch jede Tabelle mit `CONVERT TO`. Nie die App-Datenbank von phpdbadmin (T-17, eigene Meldung «lässt sich hier nicht ändern»).

## Abnahmekriterien

| Anforderung | Kriterium | Nachweis |
|---|---|---|
| O-10 | Vorauswahl aus der Adresse; Durchlauf beider Menüs | `MaintenanceControllerTest::test_menus_preselect_operation_schema_and_table_from_the_address`, `TransferControllerTest::test_menu_database_presets_the_export_of_the_whole_database`; Durchlauf in der Sichtprüfung (Playwright, beide Menüs, Wartung mit Tabelle, Export der ganzen Datenbank als SQL) |
| W-06 | je Treiber, auch «Engine kann es nicht» | `DialectServerContractTest::test_maintenance_runs_on_one_table_and_on_the_whole_schema` (jede Art auf allen vier Servern), `…::test_repair_works_for_myisam_notes_innodb_and_checksum_returns_a_number` (MySQL, MariaDB) |
| S-10 | Vorschau und Anwenden je Option | `TableActionControllerTest::test_table_options_change_engine_collation_and_counter` (MySQL, MariaDB), `…::test_table_options_are_only_for_mysql_and_mariadb` |
| S-11 | Kopie mit Daten, Verschieben, Ziel muss existieren | `TableActionControllerTest::test_move_and_copy_into_another_database_or_schema` (MySQL, MariaDB, PostgreSQL), `…::test_target_must_exist_and_sqlite_does_not_move_between_files` |
| S-12 | Vorschau und Anwenden | `SchemaControllerTest::test_changes_the_character_set_of_a_database_and_on_request_of_its_tables` (MySQL, MariaDB), `…::test_never_changes_the_character_set_of_the_app_database` |

## Commits

`333641e` Menüs (O-10) · `4d93fde` Wartung (W-06) · `76958de` Verschieben und Kopieren (S-11) · `b6e0f84` Tabellenoptionen und Zeichensatz (S-10, S-12) · veröffentlicht mit [v1.4.0](https://github.com/hoang-79/phpdbadmin/releases/tag/v1.4.0) am 13.11.2026.

## Gelernt

- **MySQL 8 hält `AUTO_INCREMENT` in `information_schema.tables` bis zu 24 Stunden zwischengespeichert** (`information_schema_stats_expiry`) — der Zähler kommt deshalb aus `SHOW CREATE TABLE`. Gefunden, weil der Test auf MySQL noch 3 statt 100 sah, MariaDB aber stimmte.
- **Ein Zahlenfeld liefert in Vue je nach Eingabe Text oder Zahl** — `.trim()` darauf warf im Browser, bevor die Vorschau überhaupt gesendet wurde. Vergleich jetzt immer über `String(…)`; gefunden nur im Durchklicken, nicht in den Tests des Servers.
- **Untermenüs von reka brauchen ein eigenes Portal**, sonst schneidet das Hauptmenü (`overflow-hidden`) sie ab — sichtbar erst im Foto.
