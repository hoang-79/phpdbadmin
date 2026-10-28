# PRL — Prioritised Requirements List (phpdbadmin)

> **Status:** v1.0 (28.10.2026) — erzeugt aus [CR-001 § 4](../00-change_request/CR-001-phpdbadmin.md) (81 Anforderungen) mit `werkzeuge/prl-bauen.py`; **nicht von Hand bearbeiten**, Zuordnung und Grössen stehen im Skript. Jede Anforderung hat eine Timebox ([Timebox-Plan](timebox-plan.md)), eine Grösse (S 1 · M 2 · L 3 · XL 5 Punkte) und eine **Priorität im Plan**: Must-Anforderungen des CR mit einem Rückfallweg laufen als *Should ↓* und rutschen bei Zeitnot in die nächste Stufe. Spalte «Feasibility» nennt, was schon belegt ist.

## 1. Kennzahlen je Stufe

| Stufe | Anforderungen | Punkte | davon Must im Plan | Must-Anteil | Must im CR |
|---|---|---|---|---|---|
| 0 Fundament | 16 | 31 | 28 | **90 %** ⚠️ | 15 von 16 |
| 1 Lesen (Alpha) | 23 | 49 | 28 | **57 %** ✅ | 15 von 23 |
| 2 Bearbeiten (v1.0) | 24 | 62 | 27 | **44 %** ✅ | 12 von 24 |
| 3 Verwalten (v1.1) | 12 | 27 | 0 | **0 %** ✅ | 0 von 12 |
| 4 Komfort und Team (v1.2) | 6 | 14 | 0 | **0 %** ✅ | 0 von 6 |
| **bis v1.0 (Stufen 0–2)** | 63 | 142 | 83 | **58 %** ✅ | 42 von 63 |

**Stufe 0 bleibt über 60 Prozent — bewusst:** Das Fundament hat keinen Rückfallweg. Ohne Anmeldung, Verbindungen, Dialekt-Schicht und Tests auf vier Datenbanken gibt es keine Stufe 1; die Entfernung von Passwort, Dashboard und Profil ist eine ausdrückliche Vorgabe. Der Puffer liegt deshalb in den Stufen 1 und 2; bis v1.0 zusammen bleibt der Must-Anteil unter 60 Prozent.

**Personentage je Timebox** (Budget je Stufe nach CR-001 § 9, obere Schätzung 53 PT, anteilig nach Punkten):

| TB | Titel | Stufe | Anforderungen | Punkte | Must | PT |
|---|---|---|---|---|---|---|
| 00 | Fundament: Starter-Kit, Anmeldung, Verbindungen, Dialekt-Schicht | 0 | 16 | 31 | 28 | ~8,0 |
| 01 | Katalog und Navigator | 1 | 9 | 18 | 11 | ~4,0 |
| 02 | Datenraster und Filter | 1 | 8 | 16 | 8 | ~3,6 |
| 03 | SQL-Editor und Befehlspalette → Alpha | 1 | 6 | 15 | 9 | ~3,4 |
| 04 | Bearbeiten mit Vorschau und Rückgängig | 2 | 8 | 21 | 10 | ~4,7 |
| 05 | Struktur ändern | 2 | 4 | 12 | 8 | ~2,7 |
| 06 | Export und Import | 2 | 4 | 9 | 3 | ~2,0 |
| 07 | Konten, Rechte, Teilen, Protokoll | 2 | 3 | 8 | 3 | ~1,8 |
| 08 | Sicherheit, Barrierefreiheit, Installation → v1.0 | 2 | 5 | 12 | 3 | ~2,7 |
| 09 | Server-Verwaltung | 3 | 5 | 9 | 0 | ~3,3 |
| 10 | Weitere Objekte, Analyse, Sicherung → v1.1 | 3 | 7 | 18 | 0 | ~6,7 |
| 11 | Komfort: Schemavergleich, Laravel, Herd, SSH | 4 | 4 | 10 | 0 | ~7,1 |
| 12 | Team und KI → v1.2 | 4 | 2 | 4 | 0 | ~2,9 |

## 2. Anforderungen je Timebox

### TB 00 — Fundament: Starter-Kit, Anmeldung, Verbindungen, Dialekt-Schicht (Stufe 0; 16 Anforderungen, 31 Punkte, Must 28)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| A-01 | Passwort entfernt | Must | **Must** | M | F-6: Fortify-Routen gelesen, Muster Basalto | Routenliste ohne diese Routen bzw. Fortify-Endpunkte lehnen ab |
| A-02 | Anmeldung mit Code per Mail | Must | **Must** | L | F-6: Vorlage Basalto (LoginController) | Tests für Erfolg, Ablauf, Wiederverwendung, falschen Code, Drosselung (benannter Limiter), unbekann… |
| A-03 | Anmeldung mit Passkey | Must | **Must** | M | F-6: laravel/passkeys 0.2.1, Software-Authenticator | Zeremonie mit virtuellem Authenticator im Test |
| A-04 | Dashboard entfernt | Must | **Must** | S | — | `/dashboard` 404 |
| A-05 | Profilseite entfernt | Must | **Must** | S | — | `/settings/profile` 404 |
| A-06 | Keine offene Registrierung | Must | Should ↓ | M | Rückfall: Konten per Konsole bis zur Einladung | Test: Einladung annehmen, abgelaufene ablehnen |
| A-09 | Anmeldungen und Fehlversuche im Protokoll | Should | Should | S | — | Eintrag mit Zeit, Konto bzw. Adresse (gehasht), IP-Prüfsumme |
| V-01 | Verbindung anlegen für MariaDB, MySQL, PostgreSQL | Must | **Must** | L | F-1/F-2: DB::build() auf vier Treibern | je Treiber ein Test gegen den echten Dienst |
| V-02 | Verbindung testen | Must | **Must** | M | F-2: Fehlerfälle je Treiber gemessen | je Fehlerart ein Test mit erzwungenem Fehler |
| V-03 | Zugangsdaten verschlüsselt | Must | **Must** | M | — | Test: Inertia-Props und JSON enthalten kein Passwort |
| V-05 | Erlaubte Hosts und SQLite-Ordner | Must | **Must** | M | F-7: Authorizer für SQLite-ATTACH | Test mit nicht erlaubtem Host und Pfad (auch `..`, Symlink) |
| B-01 | Stack | Must | **Must** | S | Pakete probeweise installiert (F-4) | Routenliste und Seitenordner ohne Starter-Kit-Reste |
| B-02 | Dialekt-Schicht | Must | **Must** | XL | F-1/F-5: Proben werden Vertragstests | Vertragstest läuft gegen alle vier Umsetzungen |
| B-03 | Tests gegen vier Datenbanken | Must | **Must** | M | MySQL 8.4.11 in Herd; Proben laufen auf vier Treibern | lokales Skript fährt alle vier |
| B-06 | App-Datenbank SQLite als Vorgabe, MariaDB, MySQL und PostgreSQL möglich | Must | **Must** | S | — | Suite läuft mit jeder App-Datenbank |
| B-08 | Qualität | Must | **Must** | S | — | lokale Prüfkette in der Reihenfolge der CI |

### TB 01 — Katalog und Navigator (Stufe 1; 9 Anforderungen, 18 Punkte, Must 11)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| V-04 | Umgebung | Must | **Must** | M | F-7: Wächter gegen Ausbruchsversuche | Test: schreibgeschützte Verbindung lehnt jede schreibende Anweisung ab, auch die Ausbruchsversuche… |
| V-06 | Verbindungen gruppieren, sortieren, suchen, Favoriten | Should | Should | S | — | Durchlauf |
| V-10 | Warnung bei Konten mit zu vielen Rechten | Should | Should | M | F-7: Kontorechte gelesen (MariaDB, PostgreSQL) | Test gegen Herd-`root` auf MariaDB und PostgreSQL (warnt) und ein Lesekonto (warnt nicht) — F-7 |
| O-01 | Navigator | Must | **Must** | L | F-3: Katalog mit 1'000 Tabellen 1–13 ms | Durchlauf mit 1'000 Tabellen flüssig (N-01) |
| O-04 | Gleiche Bildschirme und Begriffe | Must | **Must** | M | — | Bildschirmfotos derselben Tabelle in allen vier Datenbanken nebeneinander |
| O-06 | Hell und dunkel | Must | Should ↓ | M | Rückfall: hell und Deutsch zuerst | Sprachdateien vollständig (Test über alle Schlüssel, ohne Rückfall) |
| S-01 | Struktur ansehen | Must | **Must** | L | F-1: Introspektion und Dialekt-Abfragen | Tests je Treiber gegen eine Beispieltabelle mit allen Merkmalen |
| N-04 | Kompatibilität | Must | Should ↓ | M | Rückfall: aktuelle Versionen lokal, Untergrenzen in der Wochen-Matrix | Feasibility prüft die Untergrenzen |
| N-06 | Sprache | Must | **Must** | S | — | Test über Sprachdateien |

### TB 02 — Datenraster und Filter (Stufe 1; 8 Anforderungen, 16 Punkte, Must 8)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| O-03 | Reiter | Should | Should | M | — | Durchlauf |
| O-05 | Tastatur | Should | Should | S | — | Durchlauf nur mit Tastatur |
| O-09 | Leer-, Fehler- und Ladezustände mit Ursache und nächstem Schritt | Must | Should ↓ | M | Rückfall: Fehlerkarte zuerst, Leer- und Ladezustände danach | Durchlauf mit leerer Tabelle, SQL-Fehler, getrennter Verbindung, abgelaufener Sitzung |
| D-01 | Datenraster | Must | **Must** | L | F-3: Blättern über den Schlüssel 0,1–0,6 ms; Raster-Probe | Tests je Treiber mit Sortierung inkl. NULL (Reihenfolge ausdrücklich, nicht treiberabhängig) |
| D-02 | Filtern | Must | **Must** | L | — | Tests je Treiber, auch mit Umlauten und Werten, die wie SQL aussehen |
| D-03 | Fremdschlüssel folgen | Should | Should | M | — | Test je Treiber |
| D-06 | Zeilen zählen | Should | Should | S | F-3: Schätzung innerhalb 0,4 % | Test mit Schwelle |
| N-01 | Leistung | Must | **Must** | M | F-3: erste Seite 0,1–0,6 ms (DB), 281 ms (Browser) | Messung mit erzeugten Daten je Treiber, Zeit im Protokoll der Timebox |

### TB 03 — SQL-Editor und Befehlspalette → Alpha (Stufe 1; 6 Anforderungen, 15 Punkte, Must 9)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| O-02 | Befehlspalette ⌘K | Must | Should ↓ | M | Rückfall: Navigator | Test der Suchquelle |
| Q-01 | SQL-Editor | Must | **Must** | L | F-4: CodeMirror 6 installiert, Vervollständigung belegt | Durchlauf je Treiber |
| Q-02 | Ausführen | Must | **Must** | L | F-2: Abbrechen 22–29 ms, MySQL still | Test: Abbrechen beendet die Abfrage auf dem Server |
| Q-03 | Grenzen | Must | **Must** | L | F-2/F-7: Zeitlimits und Schreibschutz belegt | Tests je Treiber mit langer und mit schreibender Anweisung auf geschützter Verbindung — F-2, F-7 |
| Q-04 | Verlauf je Konto und Verbindung | Should | Should | M | — | Test |
| Q-09 | SQLite-Abfragen aus dem SQL-Editor in einem Arbeitsprozess mit Zeitgrenze | Should | Should | M | F-2: SQLite ohne Zeitlimit gemessen | Test: eine Abfrage über die Grenze wird beendet, die Oberfläche meldet es — F-2 |

### TB 04 — Bearbeiten mit Vorschau und Rückgängig (Stufe 2; 8 Anforderungen, 21 Punkte, Must 10)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| V-11 | Schreibschutz auf Zeit aufheben | Should | Should | M | Wireframes 5–7; Rückfall: Schreibschutz in der Verbindung ausschalten | Test: Freigabe läuft nach 15 Minuten ab (Uhr gestellt), Grund und Ende im Protokoll, ohne Recht 403… |
| D-04 | Bearbeiten | Must | **Must** | XL | F-5: pretend() für DML belegt | Test je Treiber: nichts geschrieben vor «Anwenden» |
| D-05 | Werte typgerecht | Must | Should ↓ | L | Rückfall: Werte als Text bearbeiten, Bildvorschau später | Tests je Typ und Treiber, Werte nach dem Speichern byte-genau verglichen |
| D-07 | Tabellen ohne Primärschlüssel | Must | Should ↓ | M | Rückfall: Tabellen ohne Primärschlüssel nur lesen | Test: mehrdeutige Zeile wird nicht geändert |
| D-09 | Rückgängig für Datenänderungen | Should | Should | L | — | Test: Änderung anwenden, rückgängig, Zustand gleich dem Ausgangszustand |
| Q-07 | Gefährliche Anweisungen | Must | **Must** | M | — | Tests mit Varianten (Kommentare, Gross-/Kleinschreibung, mehrere Anweisungen) |
| E-05 | Kopieren aus dem Raster als TSV, Markdown oder INSERT | Should | Should | S | — | Test |
| N-03 | Kein Datenverlust durch die Anwendung | Must | **Must** | L | F-5: naiver Weg verliert Kinder, Weg je Treiber nicht | Tests mit erzwungenem Fehler mitten in einer Folge und mit Elterntabelle und Kindern (naiv: 0 von 2… |

### TB 05 — Struktur ändern (Stufe 2; 4 Anforderungen, 12 Punkte, Must 8)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| S-02 | Tabelle anlegen | Must | **Must** | L | F-5: toSql() = Ausführung | Test: Vorschau (aus `Blueprint::toSql()`, nie `pretend()`) = ausgeführtes SQL |
| S-03 | Tabelle ändern | Must | **Must** | XL | F-5: Anwenden je Treiber belegt | Tests je Treiber mit Elterntabelle und abhängigen Zeilen: Daten, Indizes, Fremdschlüssel und Kinder… |
| S-04 | Tabelle umbenennen, kopieren | Must | Should ↓ | M | Rückfall: im SQL-Editor mit Bestätigung (Q-07) | Test: ohne korrekten Namen keine Ausführung |
| S-05 | Datenbank bzw. Schema anlegen und löschen | Should | Should | M | — | Test je Treiber (SQLite: neue Datei im erlaubten Ordner) |

### TB 06 — Export und Import (Stufe 2; 4 Anforderungen, 9 Punkte, Must 3)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| E-01 | Export | Must | **Must** | L | F-8: lazyById 3 MB je Million Zeilen | Test: Export und Re-Import ergeben dieselben Daten je Treiber |
| E-02 | Import | Must | Should ↓ | L | F-8: 100'000 Zeilen 143–502 ms; Rückfall: SQL-Datei im Editor | Tests mit Fehlerzeilen |
| E-06 | Statistik aktualisieren | Should | Should | S | F-3: Gegenprobe EXPLAIN vor und nach ANALYZE | Test: nach Import ist die Schätzung der Zeilenzahl aktuell — F-3 |
| B-07 | Queue für Export und Import | Should | Should | M | — | Test beider Wege |

### TB 07 — Konten, Rechte, Teilen, Protokoll (Stufe 2; 3 Anforderungen, 8 Punkte, Must 3)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| A-07 | Rollen und Rechte | Should | Should | L | — | Policy-Tests je Recht |
| V-09 | Verbindungen persönlich oder geteilt | Should | Should | M | — | Test: persönliche Verbindung ist für andere 404 |
| B-04 | Protokoll | Must | **Must** | L | F-7: Meldungen tragen Werte — Maskierung nötig | Test: jede schreibende Aktion erzeugt genau einen Eintrag |

### TB 08 — Sicherheit, Barrierefreiheit, Installation → v1.0 (Stufe 2; 5 Anforderungen, 12 Punkte, Must 3)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| O-07 | Auf Tablet und Telefon lesbar, Bearbeiten am Desktop | Should | Should | L | — | Durchlauf bei 375, 768 und 1440 px |
| O-08 | WCAG 2.2 AA in den Kernbildschirmen | Should | Should | M | — | axe ohne Befund |
| B-05 | Installation per Git oder composer create-project und einem Einrichtungsbefehl | Should | Should | M | — | Durchlauf in einem leeren Ordner unter 15 Minuten |
| N-02 | Sicherheit | Must | **Must** | L | Bedrohungsmodell T-01 bis T-18 | Prüfbericht ohne hohen Befund |
| N-05 | Kurze Hilfe in der Oberfläche | Should | Should | M | — | Doku-Prüfung im Commit |

### TB 09 — Server-Verwaltung (Stufe 3; 5 Anforderungen, 9 Punkte, Must 0)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| W-01 | Server-Übersicht | Should | Should | M | — | Test je Treiber |
| W-02 | Prozesse und Sitzungen anzeigen und beenden | Should | Should | M | F-2: KILL QUERY und pg_cancel_backend belegt | Test: beendete Sitzung ist weg |
| W-03 | Variablen und Status lesen | Could | Could | S | — | Durchlauf |
| W-04 | Datenbank-Benutzer, Rollen und Rechte geführt verwalten | Should | Should | L | — | Tests je Treiber (ohne SQLite) |
| W-05 | Wartung | Could | Could | S | — | Durchlauf |

### TB 10 — Weitere Objekte, Analyse, Sicherung → v1.1 (Stufe 3; 7 Anforderungen, 18 Punkte, Must 0)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| D-08 | Suchen und Ersetzen | Could | Could | M | — | Test je Treiber |
| S-06 | Views, Routinen, Trigger, Sequenzen, Typen und Enums | Should | Should | XL | — | Test je Objektart und Treiber |
| S-07 | Beziehungsdiagramm aus den Fremdschlüsseln, als SVG exportierbar | Should | Should | L | — | Durchlauf mit Basalto-Datenbank |
| Q-05 | Ergebnisse bearbeiten, wenn die Abfrage eine Tabelle mit Primärschlüssel liest | Could | Could | M | — | Test |
| Q-06 | EXPLAIN als Baum mit Hinweisen | Should | Should | M | — | Durchlauf je Treiber |
| E-03 | Sicherung und Wiederherstellung über die Werkzeuge des Servers | Should | Should | L | — | Test überspringt sichtbar, wenn das Werkzeug fehlt |
| E-04 | Export als Excel | Could | Could | S | — | Test |

### TB 11 — Komfort: Schemavergleich, Laravel, Herd, SSH (Stufe 4; 4 Anforderungen, 10 Punkte, Must 0)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| V-07 | Herd-Dienste und .env der Herd-Sites als Vorschläge erkennen | Could | Could | M | — | Durchlauf auf Hoangs Mac |
| V-08 | SSH-Tunnel | Could | Could | L | — | Test gegen einen lokalen SSH-Dienst |
| S-08 | Schemavergleich zweier Datenbanken mit Änderungsskript | Could | Could | L | — | Test mit zwei Ständen |
| S-09 | Laravel-Migration aus einer bestehenden Tabelle erzeugen | Could | Could | M | — | Test: Migration erzeugt dieselbe Tabelle auf einem anderen Treiber |

### TB 12 — Team und KI → v1.2 (Stufe 4; 2 Anforderungen, 4 Punkte, Must 0)

| Nr. | Anforderung | CR | Plan | Grösse | Feasibility / Rückfall | Abnahme (Kurzform, vollständig im CR) |
|---|---|---|---|---|---|---|
| A-08 | Aktive Sitzungen sehen und beenden | Could | Could | M | — | Test: beendete Sitzung ist abgemeldet |
| Q-08 | SQL-Hilfe mit KI — nur eingeschaltet, nur mit eigenem Schlüssel, nur das Schema wird gesendet | Could | Could | M | — | Test mit Attrappe: keine Datenzeilen im Auftrag |

## 3. Regeln

- **Must im Plan** trägt den Meilenstein der Stufe (Alpha, v1.0, v1.1, v1.2); **Should ↓** ist im CR Must, hat aber einen Rückfallweg und darf bei Zeitnot in die nächste Stufe rutschen — der CR bleibt unverändert, die Verschiebung steht im Timebox-Protokoll.
- Grösse ist eine relative Schätzung; die Personentage je Timebox ergeben sich aus dem Budget der Stufe anteilig nach Punkten — der [Timebox-Plan](timebox-plan.md) übernimmt sie.
- Das Skript prüft, dass jede Anforderung genau eine Timebox in ihrer Stufe hat; neue Anforderungen im CR brechen den Lauf, bis sie zugeordnet sind.
- Änderungen an Zuordnung oder Grösse nur im Skript; danach `python3 dokumentationen/werkzeuge/prl-bauen.py` und das PDF neu bauen.
