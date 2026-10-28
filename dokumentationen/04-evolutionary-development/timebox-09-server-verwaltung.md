# Timebox 09 — Server-Verwaltung

**Stand:** 29.10.2026 · ✅ gebaut und getestet in drei Scheiben auf einem eigenen Zweig (Arbeitskopie), vorgezogen vor v1.0 — Browser-Durchlauf und Zusammenführen im Hauptstrang offen.

## Ziel

Den Server hinter einer Verbindung ansehen und geführt verwalten — Übersicht, Prozesse, Variablen, Konten und Rechte, Wartung —, mit derselben Bedienung für MySQL, MariaDB, PostgreSQL und SQLite und ohne dass etwas ungesehen passiert.

## Umfang

| Nr. | Anforderung (CR-001 § 4.8) | Prio | Ergebnis |
|---|---|---|---|
| W-01 | Server-Übersicht: Version, Laufzeit, Grösse je Datenbank, Zeichensätze | S | ✅ |
| W-02 | Prozesse und Sitzungen anzeigen und beenden | S | ✅ |
| W-03 | Variablen und Status lesen | C | ✅ (Durchlauf offen) |
| W-04 | Benutzer, Rollen und Rechte geführt verwalten mit Vorschau | S | ✅ |
| W-05 | Wartung: ANALYZE, VACUUM, OPTIMIZE, Integritätsprüfung | C | ✅ (Durchlauf offen) |

Kein eigenes Wireframe (Timebox-Plan: «in TB 09 entworfen»); gestaltet im Stil der Wireframes 05 (Arbeitsbildschirm), 06 (SQL-Vorschau) und 08 (Struktur).

## Was entstanden ist

**Dialekt (ADR-001).** Der Vertrag kennt jetzt `serverOverview`, `sessionId`, `processes`, `endProcessStatement`/`endProcess`, `variables`, `statusValues`, `accounts`, `currentAccount`, `privilegeChoices`, `createAccountStatements`, `dropAccountStatements`, `privilegeStatements`, `maintenanceOperations`, `maintenanceForTables`, `maintenanceStatements` und `runMaintenance` — umgesetzt in allen vier Dialekten; SQLite gibt `null` bzw. leere Listen, wo es Prozesse und Konten nicht gibt. Was ändert, plant der Dialekt nur als `PlannedStatement` (echter Text und Text für Vorschau und Protokoll, Passwort maskiert).

**Ändernde Aktionen.** `TargetConnections::withWritable()` öffnet für genau eine Aktion eine Verbindung ohne Schreibschutz — nie für eine schreibgeschützte (`ReadOnlyConnection`), getrennt von den Leseverbindungen und danach sofort freigegeben. `ServerPlans` plant aus geprüften Eingaben; jeder Name eines Ziels muss im Katalog stehen. `ServerChanges` zeigt die Vorschau über die Leseverbindung und führt über die beschreibbare aus, Anweisung für Anweisung, ohne Abfrage-Ereignisse (dort stünde das Passwort); jede Aktion ergibt genau einen Eintrag im Protokoll — `server.kill`, `server.user.create`, `server.user.drop`, `server.grant`, `server.revoke`, `server.maintenance` —, gelungen oder gescheitert, mit SQL ohne Geheimnisse. `AuditLog::record()` nimmt dafür das SQL auf.

**Seiten.** Unter `c/{connection}/server` fünf Inertia-Seiten im Arbeitsbildschirm (`workspace/server/Overview`, `Processes`, `Variables`, `Accounts`, `Maintenance`), Einstieg «Server» im Menü der Verbindung:

| Methode | Adresse | Name |
|---|---|---|
| GET | `server` | `workspace.server` |
| GET | `server/processes` | `workspace.server.processes` |
| POST | `server/processes/{process}/end` | `workspace.server.processes.end` |
| GET | `server/variables` | `workspace.server.variables` |
| GET | `server/accounts` | `workspace.server.accounts` |
| POST | `server/accounts/preview` | `workspace.server.accounts.preview` (JSON) |
| POST | `server/accounts` | `workspace.server.accounts.apply` |
| GET | `server/maintenance` | `workspace.server.maintenance` |
| POST | `server/maintenance/preview` | `workspace.server.maintenance.preview` (JSON) |
| POST | `server/maintenance` | `workspace.server.maintenance.run` |

## Abnahmekriterien

| Anforderung | Kriterium | Nachweis |
|---|---|---|
| W-01 | Test je Treiber | Vertrag: Version, Laufzeit (SQLite keine), Zeichensatz, Grösse der Test-Datenbank > 0, PostgreSQL zusätzlich je Schema, SQLite Seitengrösse, Journal und Datei — vier Treiber; Seite je Server-Treiber und SQLite |
| W-02 | beendete Sitzung ist weg | Vertrag je Server-Treiber: zweite Sitzung öffnen, Kennung über `connection_id()` bzw. `pg_backend_pid()`, beenden — danach nicht mehr in der Liste und tot (eigene PDO-Verbindung scheitert), ein zweites Beenden meldet «gibt es nicht»; Abbrechen stoppt `sleep` in einer asynchronen Sitzung (mysqli, pgsql), die Sitzung bleibt. Endpunkt je Server-Treiber: Weiterleitung, Meldung, genau ein Eintrag `server.kill`. Die eigene Sitzung ist markiert und abgewiesen |
| W-03 | Durchlauf | Vertrag vier Treiber, Seite für SQLite und PostgreSQL; Browser-Durchlauf offen |
| W-04 | Tests je Treiber (ohne SQLite) | Vertrag je Server-Treiber: Konto mit einem Passwort, das wie SQL aussieht, anlegen, mit diesem Passwort anmelden, Recht auf eine Tabelle erteilen, mit `SHOW GRANTS` bzw. `has_table_privilege` und mit einem echten Zugriff prüfen, entziehen, löschen; Rechte auf Server, Datenbank und (PostgreSQL) Schema erscheinen in der Liste und verschwinden wieder. Endpunkt je Server-Treiber: Vorschau maskiert und ändert nichts, Lebenszyklus mit vier Einträgen im Protokoll ohne Passwort |
| W-05 | Durchlauf | Vertrag je Treiber und Art (11 Fälle) für eine Tabelle und das ganze Schema ohne Fehler; SQLite erkennt einen beschädigten Index; Endpunkt mit Ergebnis je Tabelle, VACUUM auf SQLite trotz ATTACH-Wächter; Browser-Durchlauf offen |

Querschnitt: ohne «verwalten» 403, schreibgeschützt 403 (auch für den Besitzer), fremde Verbindung 404, Produktion erst mit dem eingetippten Namen — für alle fünf ändernden Endpunkte, ohne Eintrag im Protokoll. Eingaben, die wie SQL aussehen (Kontoname, Host, Recht, Datenbank, Tabelle «*», Ebene, Prozesskennung), werden vor jeder Anweisung abgewiesen.

## Gestaltungsentscheide (statt Wireframe-Abweichungen)

| Bereich | Entscheid | Grund |
|---|---|---|
| Einstieg | Eintrag «Server» im Menü der Verbindung; Navigator unverändert, zeigt die Schemas ohne Auswahl | Navigator und Layout bearbeitet parallel jemand anderes |
| Bereiche | Reiter wie in der Strukturansicht (Wireframe 08), brechen um statt zu scrollen | gleiche Bedienung wie im übrigen Arbeitsbildschirm |
| Bestätigung | Dialog im Stil der SQL-Vorschau (Wireframe 06): nummerierte Anweisungen, Hinweise je Treiber, auf Produktion Name der Verbindung, Fehler des Servers im Dialog | eine Bestätigung für Prozesse, Konten und Wartung |
| Konten | erst ein Formular (anlegen bzw. Ebene, Ziel, Rechte), dann die Vorschau; Löschen geht direkt zur Vorschau | nichts läuft ohne Vorschau, auch nicht «nur» ein REVOKE |
| Ebenen | MySQL/MariaDB: Server, Datenbank, Tabelle; PostgreSQL: Server (vordefinierte Rollen wie `pg_monitor`), Datenbank, Schema (USAGE/CREATE und Rechte auf allen Tabellen darin), Tabelle | PostgreSQL kennt keine Rechte auf «*.*» |
| Schmale Fenster | Prozesse, Konten und Variablen als Karten bzw. umbrechende Zeilen, keine breiten Tabellen | 375 px lesbar (O-07) |
| Gesperrt | Knöpfe bleiben sichtbar, aber gesperrt, mit Grund (schreibgeschützt bzw. ohne «verwalten») | man sieht, was ginge |
| SQLite | gleiche Reiter; Prozesse und Konten mit klarer Erklärung, Variablen = lesbare PRAGMAs, Status = Optionen der Bibliothek | gleiche Bildschirme für alle vier (O-04) |

## Entscheide in der Timebox

| Entscheid | Begründung |
|---|---|
| Bezeichner je Segment selbst quoten (Anführungszeichen verdoppelt) statt `Grammar::wrap()` | `wrap()` trennt an Punkten und « as » und lässt «*» ungequotet — eine Tabelle «*» würde zu «alle Tabellen» |
| Zeichenketten (Passwort, Host, Kontoname bei MySQL) über `PDO::quote`, Rechte nur aus der festen Liste je Ebene | DDL wie `CREATE USER` nimmt keine Platzhalter |
| MySQL/MariaDB: `_` und `%` im Datenbanknamen eines GRANT maskiert | auf Datenbank-Ebene sind sie Platzhalter — «my_db» träfe auch «myXdb» |
| Vorschau als JSON (`useHttp`), Ausführen als Inertia-POST mit Weiterleitung und Meldung | dieselbe Planung für beide; die Seite lädt nach einer Änderung neu |
| Ein Eintrag im Protokoll auch für gescheiterte Versuche (`outcome: failed`, Meldung maskiert) | wer was versucht hat, gehört ins Protokoll |
| Meldungen des Servers aus `getPrevious()` der `QueryException`, Geheimnisse maskiert | Laravel hängt die Anweisung samt Werten an die Meldung |
| PostgreSQL meldet sich als `application_name = phpdbadmin` | eigene Sitzungen in `pg_stat_activity` erkennbar |
| PostgreSQL: `DROP ROLE` ohne `DROP OWNED` | `DROP OWNED` löscht Objekte; lieber ablehnen lassen und den Hinweis zeigen |
| Wartung mit eigenem Zeitlimit (`PHPDBADMIN_MAINTENANCE_TIMEOUT_MS`, Vorgabe 10 Minuten) | OPTIMIZE und VACUUM grosser Tabellen brauchen länger als eine Abfrage |
| SQLite-VACUUM: `ATTACH ''` im Authorizer erlaubt (Zeile aus dem Hauptstrang) | VACUUM hängt intern eine leere Datenbank an |

## Tests

126 neue Tests; Suite gesamt 449, davon 437 bestanden und 12 übersprungen (die schweren Proben auf Wunsch). Testläufe mit `PHPDBADMIN_TEST_DATABASE=phpdbadmin_test_server`; Konten heissen `phpdbadmin_w04_…` und werden im Test wieder entfernt (`finally`).

| Datei | Tests | MySQL | MariaDB | PostgreSQL | SQLite |
|---|---|---|---|---|---|
| `Dialects/DialectServerContractTest` | 46 | 12 | 15 | 11 | 8 |
| `Http/Controllers/Workspace/Server/*` (fünf Dateien) | 53 | 5 | 25 | 6 | 17 |
| `Http/Requests/Workspace/Server/ServerChangeRequestTest` | 18 | — | — | — | 18 |
| `Services/Server/ServerPlansTest` | 7 | 3 | 2 | 2 | — |
| `Connections/TargetConnectionsTest` (neu: beschreibbare Verbindung) | 2 | — | — | — | 2 |
| **zusammen** | **126** | **20** | **42** | **19** | **45** |

Prüfkette vor jedem Commit: `vp check`, `vue-tsc`, Pint, Larastan, PHPUnit — grün.

## Commits

`5d54279` Scheibe 1: Dialekt für die Server-Verwaltung · `2272649` Scheibe 2: Grundlage für ändernde Aktionen · `87b5a20` Scheibe 3: Seiten und Endpunkte · Doku (dieser Commit).

## Offen

- **Browser-Durchlauf** bei 1440, 768 und 375 px, hell und dunkel, gegen einen Herd-Workspace — Hauptstrang.
- **PostgreSQL schickt das Passwort im Klartext** (`CREATE ROLE … PASSWORD '…'`); mit `log_statement = ddl` steht es im Server-Log. Abhilfe wäre ein SCRAM-Verifier, im Client berechnet — PHP fehlt dafür SASLprep.
- **Rollen in MariaDB** werden gelistet, aber nicht gelöscht (`DROP ROLE`); MySQL-Rollen erscheinen als gesperrte Konten.
- **Anonyme Konten in MariaDB** (Herd: `''@'localhost'`) gehen `'app'@'%'` vor — ein neues Konto meldet sich lokal erst mit Host `localhost` an. Ein Hinweis in der Oberfläche fehlt.
- Integritätsprüfung für PostgreSQL (`amcheck`) nicht im Umfang; die Prozessliste aktualisiert sich nur auf Knopfdruck.
- Grosse Wartung kann an der Laufzeitgrenze von PHP scheitern, bevor das Zeitlimit des Servers greift.

## Gelernt

- `Grammar::wrap()` ist kein Quoting für beliebige Namen (Punkte, « as », «*») — für Bezeichner aus Eingaben eigenes Quoting je Segment (Lektion für den Vault).
- MariaDB hängt an `SHOW GRANTS` den Passwort-Hash (`IDENTIFIED BY PASSWORD '*…'`) — vor der Anzeige entfernen.
- Inertia-Tests verlangen die Seitendatei: Endpunkte und Seiten gehören in dieselbe Scheibe.
- Pint (`php_unit_method_casing`) benennt private Hilfsmethoden, die mit «test» beginnen, in Testklassen in `test_…` um — Hilfsmethoden anders benennen.
