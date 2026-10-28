# 02 — Feasibility

> **PDF der Phase:** [feasibility.pdf](feasibility.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 02`).

📋 **Geplant** — beginnt nach dem Entscheid «Weiterführen» auf [CR-001](../00-change_request/CR-001-phpdbadmin.md). Jeder Prüfpunkt wird mit Code auf allen vier Datenbanken belegt (Spike im Repo, danach verworfen oder übernommen), nicht nur beschrieben.

| Nr. | Prüfpunkt | Frage | Belegt durch |
|---|---|---|---|
| F-1 | Katalog | Reicht die Laravel-Introspektion (`getSchemas`, `getTables`, `getViews`, `getTypes`, `getColumns`, `getIndexes`, `getForeignKeys`) auf MySQL 8.4, MariaDB 10.11, PostgreSQL 18 und SQLite? Was fehlt (Check-Constraints, Trigger, Kommentare, Kollationen)? | Spike mit einer Beispieltabelle mit allen Merkmalen je Treiber |
| F-2 | Verbindungen | `DB::build()` je Anfrage: Zeitlimits je Treiber, nur lesen (read-only-Transaktion, `query_only`), Abbrechen laufender Abfragen (`KILL QUERY`, `pg_cancel_backend`), Zeitzone UTC je Sitzung | Spike mit langer Abfrage und schreibender Anweisung auf geschützter Verbindung |
| F-3 | Raster | Erste Seite einer Tabelle mit 1 Mio. Zeilen unter 1 s; Blättern über den Schlüssel; Virtualisierung im Browser — eigenes Raster oder TanStack? | Messung je Treiber, Abfrageplan gelesen |
| F-4 | SQL-Editor | CodeMirror 6 mit Dialekten und Vervollständigung aus dem Schema; Paket trocken installieren, Grösse im Build | `npm` trocken, Probe-Seite |
| F-5 | DDL mit Vorschau | `Blueprint::toSql()` und `pretend()` liefern genau das SQL, das danach läuft — auch beim SQLite-Tabellenumbau? | Spike: Vorschau = Ausführung, Introspektion danach |
| F-6 | Anmeldung | Fortify 1.41 nur mit Passkeys, Code-Anmeldung, Passwort-Endpunkte weg (Muster Basalto TB 00) | Spike mit virtuellem Authenticator |
| F-7 | Sicherheit | Hosts-Liste gegen SSRF, verschlüsselte Zugangsdaten, CSP, Protokoll; Bedrohungsmodell für ein Werkzeug mit Zugang zu allen Datenbanken | Bedrohungsmodell, Tests mit fremdem Host |
| F-8 | Export und Import | Streaming grosser Daten, Queue, Speicherverbrauch; CSV-Probelauf | Messung mit 1 Mio. Zeilen |
| F-9 | Lizenz und Aufwand | Lizenz MIT entschieden (R-40): Sind alle Abhängigkeiten MIT-verträglich? Aufwand gegen Nutzen nach den Proben | Lizenzliste aus `composer licenses` und npm, Rechnung |
| F-10 | Name absichern | Name phpdbadmin entschieden (R-04): Markenlage zu «phpMyAdmin» (IGE, EUIPO), `phpdbadmin.ch` sichern, eigenständiger Auftritt | Recherche mit Fundstellen |

**Diagramme** (`diagrams/`, geplant): Ablauf Verbindung je Anfrage · Bedrohungsmodell · Messergebnisse Raster.
