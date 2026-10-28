# Funktionsraster — phpMyAdmin, phpPgAdmin, Adminer → phpdbadmin

**Stand:** 28.10.2026 · v0.1. Grundlage: Controller und Seiten der lokalen Kopien von phpMyAdmin 5.2.2 und phpPgAdmin 7.13.0 (nur Dateinamen und Bereiche gelesen, kein Code übernommen), die Funktionsliste auf adminer.org (6.2.0) und der Artikel zu Jaxon DbAdmin ([Marktbild § 6](marktbild.md#6-quellen-abgerufen-28102026)).

**Zeichen:** ✓ vorhanden · ◐ teilweise oder umständlich · — fehlt. **Vorschlag:** **v1.0** (Kern, Stufe 0–2) · **v1.1** (Verwalten, Stufe 3) · **v1.2** (Komfort und Team, Stufe 4) · **Nein**. Die Spalte «Anforderung» verweist auf [CR-001 § 4](CR-001-phpdbadmin.md#4-anforderungen).

## 1. Anmeldung und Zugang

| Funktion | phpMyAdmin | phpPgAdmin | Adminer | Vorschlag | Anforderung |
|---|---|---|---|---|---|
| Anmeldung mit Datenbank-Benutzer und -Passwort | ✓ | ✓ | ✓ | **Nein** — eigene Konten | A-01 |
| Eigene Konten der Anwendung | — | — | — | **v1.0** | A-06 |
| Anmeldung ohne Passwort (Code per Mail) | — | — | ◐ (ab 6.0) | **v1.0** | A-02 |
| Passkey | — | — | — | **v1.0** | A-03 |
| Zwei-Faktor (TOTP) | ✓ | — | ◐ (Plugin) | **Nein** — Passkey ersetzt es | A-01 |
| Rechte je Verbindung (lesen, schreiben, verwalten) | ◐ (Benutzergruppen, Menüs ausblenden) | — | — | **v1.0** | A-07 |
| Protokoll der Anmeldungen | — | — | — | **v1.0** | A-09 |

## 2. Verbindungen

| Funktion | phpMyAdmin | phpPgAdmin | Adminer | Vorschlag | Anforderung |
|---|---|---|---|---|---|
| Mehrere Server | ✓ (Konfigurationsdatei) | ✓ (Konfigurationsdatei) | ✓ (Eingabe beim Anmelden) | **v1.0** — in der Oberfläche anlegen | V-01 |
| Verbindung testen mit verständlichem Fehler | ◐ | ◐ | ◐ | **v1.0** | V-02 |
| Gespeicherte Zugangsdaten verschlüsselt | ◐ (Cookie verschlüsselt) | — | ◐ (Sitzung) | **v1.0** | V-03 |
| Umgebung (lokal, Test, Produktion) mit Farbe, Schreibschutz | — | — | — | **v1.0** | V-04 |
| Erlaubte Hosts (kein Sprungbrett ins Netz) | ◐ (`AllowArbitraryServer` aus) | ◐ | ◐ (Plugin) | **v1.0** | V-05 |
| SQLite-Dateien | — | — | ✓ | **v1.0** | V-01 |
| SSH-Tunnel | — | — | — | **v1.2** | V-08 |
| Herd-Dienste und `.env` erkennen | — | — | — | **v1.2** | V-07 |

## 3. Bedienung

| Funktion | phpMyAdmin | phpPgAdmin | Adminer | Vorschlag | Anforderung |
|---|---|---|---|---|---|
| Navigationsbaum | ✓ | ✓ (Baum, Frames) | ◐ (Liste) | **v1.0** | O-01 |
| Befehlspalette, Schnellsuche über Objekte | ◐ (Filter im Baum) | — | — | **v1.0** | O-02 |
| Reiter für mehrere offene Objekte | — | — | — | **v1.0** | O-03 |
| Gleiche Bedienung über alle Datenbanken | — | — | ✓ | **v1.0** | O-04 |
| Dunkelmodus | ◐ (Themes) | — | ◐ (Designs) | **v1.0** | O-06 |
| Mehrsprachig | ✓ | ✓ | ✓ | **v1.0** DE/EN | O-06, N-06 |
| Mobil lesbar | ◐ | — | ◐ | **v1.0** lesen | O-07 |

## 4. Daten

| Funktion | phpMyAdmin | phpPgAdmin | Adminer | Vorschlag | Anforderung |
|---|---|---|---|---|---|
| Blättern, sortieren | ✓ | ✓ | ✓ | **v1.0** | D-01 |
| Filtern geführt / freies WHERE | ✓ (Suchen, Query by Example, Zoom-Suche) | ◐ (Suchen) | ✓ | **v1.0** | D-02 |
| Fremdschlüssel folgen | ✓ | — | ✓ | **v1.0** | D-03 |
| Bearbeiten direkt in der Zelle | ◐ (Doppelklick, je Zelle sofort gespeichert) | — | — | **v1.0** mit Sammeln und Vorschau | D-04 |
| SQL-Vorschau vor dem Schreiben | ◐ (nur «SQL anzeigen» nachher) | — | — | **v1.0** | D-04, P-02 |
| Rückgängig für Datenänderungen | — | — | — | **v1.0** (begrenzt) | D-09 |
| JSON, Binärdaten, Datum typgerecht | ◐ (Transformationen) | ◐ | ◐ | **v1.0** | D-05 |
| Geschätzte Zeilenzahl für grosse Tabellen | ✓ (InnoDB-Schätzung) | — | ✓ | **v1.0** | D-06 |
| Suchen und Ersetzen | ✓ | — | — | **v1.1** | D-08 |
| Suche über alle Tabellen | ✓ | ✓ (Finden) | ✓ | **v1.1** | D-08 |
| Diagramm aus Abfrageergebnis | ✓ | — | — | **Nein** (BI) | — |
| GIS-Darstellung | ✓ | — | — | **Nein** | — |

## 5. Struktur

| Funktion | phpMyAdmin | phpPgAdmin | Adminer | Vorschlag | Anforderung |
|---|---|---|---|---|---|
| Spalten, Indizes, Fremdschlüssel ansehen | ✓ | ✓ | ✓ | **v1.0** | S-01 |
| DDL anzeigen | ✓ | ◐ | ✓ | **v1.0** | S-01 |
| Tabelle anlegen und ändern | ✓ | ✓ | ✓ | **v1.0** mit Vorschau | S-02, S-03 |
| Umbenennen, kopieren, leeren, löschen | ✓ (Operationen) | ✓ | ✓ | **v1.0** mit Namenseingabe | S-04 |
| Datenbank oder Schema anlegen | ✓ | ✓ (Schemas) | ✓ | **v1.0** | S-05 |
| Views | ✓ | ✓ | ✓ | **v1.1** | S-06 |
| Routinen, Trigger | ✓ | ✓ | ✓ | **v1.1** | S-06 |
| Events (MySQL) | ✓ | — | ✓ | **v1.1** | S-06 |
| Sequenzen, Typen, Domänen, Regeln (PostgreSQL) | — | ✓ | ◐ | **v1.1** (Sequenzen, Typen) | S-06 |
| Partitionen | ✓ | — | ◐ | **v1.2** | — |
| Beziehungsdiagramm | ✓ (Designer) | — | ✓ (Schema) | **v1.1** | S-07 |
| Datenwörterbuch, Normalisierung | ✓ | — | — | **Nein** | — |
| Änderungsverfolgung (Tracking) | ✓ | — | — | **Nein** — Protokoll deckt es | B-04 |
| Schemavergleich | — | — | — | **v1.2** | S-08 |
| Laravel-Migration aus Tabelle | — | — | — | **v1.2** | S-09 |

## 6. SQL

| Funktion | phpMyAdmin | phpPgAdmin | Adminer | Vorschlag | Anforderung |
|---|---|---|---|---|---|
| Editor mit Hervorhebung | ✓ (CodeMirror 5) | ◐ | ◐ | **v1.0** | Q-01 |
| Autovervollständigung aus dem Schema | ✓ | — | — | **v1.0** | Q-01 |
| Formatieren | ✓ | — | — | **v1.0** | Q-01 |
| Mehrere Ergebnisse, Abbrechen | ◐ | — | ◐ | **v1.0** | Q-02 |
| Zeitlimit, Zeilengrenze, nur lesen | ◐ | — | ◐ | **v1.0** | Q-03 |
| Verlauf, gespeicherte Abfragen | ✓ (Lesezeichen, Verlauf) | ✓ (Verlauf) | ◐ (Verlauf) | **v1.0** | Q-04 |
| Gefährliche Anweisungen erkennen | ◐ (Warnung bei DROP) | — | — | **v1.0** | Q-07 |
| EXPLAIN | ✓ (Tabelle) | ✓ | ✓ | **v1.1** als Baum | Q-06 |
| Ergebnisse bearbeiten | ✓ | — | ◐ | **v1.1** | Q-05 |
| KI-Hilfe | — | — | — | **v1.2** optional | Q-08 |

## 7. Export und Import

| Funktion | phpMyAdmin | phpPgAdmin | Adminer | Vorschlag | Anforderung |
|---|---|---|---|---|---|
| Export SQL, CSV | ✓ | ✓ (über pg_dump) | ✓ | **v1.0** | E-01 |
| Export JSON | ✓ | — | ◐ (Plugin) | **v1.0** | E-01 |
| Export Excel, PDF, LaTeX u. a. | ✓ | — | — | **v1.1** Excel, sonst **Nein** | E-04 |
| Import CSV mit Spaltenzuordnung | ◐ (ohne Zuordnung) | ✓ | ◐ | **v1.0** mit Probelauf | E-02 |
| Import SQL-Datei | ✓ | ✓ | ✓ | **v1.0** | E-02 |
| Grosse Exporte im Hintergrund | — | — | — | **v1.0** | E-01 |
| Kopieren als TSV, Markdown, INSERT | ◐ | — | — | **v1.0** | E-05 |
| Sicherung und Wiederherstellung mit den Server-Werkzeugen | — | ✓ (pg_dump) | — | **v1.1** | E-03 |

## 8. Server-Verwaltung

| Funktion | phpMyAdmin | phpPgAdmin | Adminer | Vorschlag | Anforderung |
|---|---|---|---|---|---|
| Server-Übersicht, Grösse je Datenbank | ✓ | ✓ | ◐ | **v1.1** | W-01 |
| Prozesse anzeigen und beenden | ✓ | ✓ | ✓ | **v1.1** | W-02 |
| Variablen und Status | ✓ (Monitor, Berater) | ◐ | ✓ | **v1.1** lesen | W-03 |
| Benutzer und Rechte | ✓ | ✓ (Rollen, Gruppen) | ✓ | **v1.1** | W-04 |
| Wartung (ANALYZE, VACUUM, OPTIMIZE) | ✓ | ✓ | ◐ | **v1.1** | W-05 |
| Replikation, Binärlog | ✓ | — | — | **Nein** | — |
| Tablespaces, Engines, Plugins | ✓ | ✓ (Tablespaces) | — | **Nein** (nur anzeigen in W-01) | — |

## 9. Zusammenfassung

Der Kern von phpdbadmin v1.0 deckt das ab, was alle drei Vorbilder gemeinsam haben (Blättern, Filtern, Struktur, SQL, Export, Import), und legt das darauf, was keines hat: **eigene Konten ohne Passwort, Rechte je Verbindung, Sammeln mit SQL-Vorschau, Rückgängig, Umgebungsfarbe mit Schreibschutz, Befehlspalette und gleiche Bedienung über vier Datenbanken.** Die Server-Verwaltung, in der phpMyAdmin am breitesten ist, folgt bewusst in v1.1 — sie wird seltener gebraucht und ist je Datenbank am verschiedensten.
