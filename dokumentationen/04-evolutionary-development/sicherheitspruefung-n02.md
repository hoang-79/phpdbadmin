# Sicherheitsprüfung N-02 — White-Box nach OWASP

**Stand:** 29.10.2026 · Abnahme v1.0: «Prüfbericht ohne hohen Befund» — **erreicht nach Behebung** (`cab3ec5`); zweiter Durchgang über TB 05–12 ohne hohen Befund, mittlere und niedrige behoben.

Die Prüfung lief als eigener, nur lesender Durchgang über Quellcode, Tests und Proben gegen die lokalen Server (MariaDB, MySQL, PostgreSQL); Grundlage war das Bedrohungsmodell T-01 bis T-19 ([Feasibility](../02-feasibility/sicherheit-und-bedrohungen.md)). Sie fand **einen hohen**, drei mittlere und sieben niedrige Befunde. Der Bericht steht unten unverändert; hier, was daraus wurde.

## Behebung

| Befund | Schwere | Behebung | Nachweis |
|---|---|---|---|
| H-01 DSN-Injektion über den Datenbanknamen umgeht die erlaubten Hosts | hoch | Namen mit `;` `'` `"` `=` `\` oder Steuerzeichen abgewiesen — am Feld (`ValidatesConnectionSettings`) **und** bei jedem Verbinden (`HostPolicy::guardDatabase` in `ConnectionFactory::address`), also auch für gespeicherte Verbindungen und Datenbanken aus «alle Datenbanken»; Prüfung fail-closed | `HostPolicyTest::test_database_names_that_would_change_the_dsn_are_refused` (7 Fälle), `TestConnectionControllerTest::test_database_name_cannot_smuggle_a_second_host_into_the_dsn`, `…::test_stored_connection_with_such_a_name_never_connects` |
| M-01 App-Datenbank als Ziel, wenn sie nicht SQLite ist | mittel | gleiche Adresse (Loopback gleich behandelt), gleicher Port, gleicher Name, gleiche Treiberfamilie (MySQL = MariaDB) → `DatabaseNotAllowed`; `phpdbadmin:check` warnt, wenn der Server der App-Datenbank erlaubt ist (Restrisiko MySQL: Lesen über `datenbank.tabelle` mit einem Konto, das Rechte darauf hat). **Nachtrag 29.10.2026:** `DROP DATABASE` (Schema-Menü und Navigator) prüft dieselbe Schranke — vorher hätte eine MySQL/MariaDB-Verbindung ohne festen Datenbanknamen die App-Datenbank auf demselben Server löschen können; das neue PostgreSQL-`DROP DATABASE` ebenso | `HostPolicyTest::test_the_app_database_on_a_server_is_never_a_target`, `SchemaControllerTest` (App-Datenbank auf PostgreSQL und MariaDB nicht löschbar) |
| M-02 Aufträge prüfen Recht und Schreibschutz beim Ausführen nicht erneut; Importer öffnen die Sitzung selbst | mittel | `TransferRunner::authorize()` vor jedem Lauf (Konto aktiv, Recht `write` bzw. `view`, Schreibschutz); Importer und Statistik rufen `TargetConnections::assertWritable()` vor `set session transaction read write` | `TransferRunnerTest::test_import_queued_before_the_connection_became_read_only_writes_nothing` (MariaDB), `…::test_export_of_an_account_that_lost_access_does_not_run` |
| M-03 Drosselung der Code-Prüfung nur je Sitzung | mittel | zusätzlich je Mail-Adresse (Fingerabdruck): 10 je Minute, 30 je Stunde | `LoginControllerTest::test_code_guesses_are_throttled_per_address_even_with_a_new_session_each_time` (jeder Versuch von anderer IP) |
| N-01 keine Sicherheits-Header, kein CSP | niedrig | `SecurityHeaders`: CSP mit Nonce je Antwort (`script-src 'self' 'nonce-…'`, `frame-ancestors 'none'`, `object-src 'none'`, `base-uri`, `form-action`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, COOP, Permissions-Policy, HSTS in Produktion über HTTPS. In der Umgebung `local` nur `Report-Only`, weil Laravel Herd ein eigenes Inline-Skript einfügt; Browser-Durchlauf (Raster, SQL-Editor, Export, Konto): keine Verletzung der App selbst | `SecurityHeadersTest` |
| N-02 Route `storage/{path}` über dem privaten Speicher | niedrig | `'serve' => false` auf der Disk `local` | `route:list` ohne `storage/*` |
| N-03 keine CSRF-Probe mit echter Middleware | niedrig | Test mit eingeschalteter `PreventRequestForgery`: fremde Herkunft und fehlendes Token → 419 | `RequestForgeryTest` |
| N-04 Einladung meldet in ein bestehendes Konto an | niedrig | Einladung legt nur noch an; gibt es die Adresse schon, Meldung «mit einem Code anmelden» | `InvitationControllerTest::test_an_invitation_never_signs_into_an_existing_account` |
| N-05 Passwörter im Verlauf im Klartext | niedrig | `Masking::secrets()` im Verlauf (`IDENTIFIED BY`, `PASSWORD`, `SET PASSWORD FOR … =`); gespeicherte Abfragen bleiben bewusst wörtlich (ausdrückliche Handlung) | `MaskingTest::test_secrets_are_masked_and_the_rest_stays_readable`, `SqlControllerTest::test_history_keeps_no_passwords` |
| N-06 Leser bekommen eine beschreibbare Sitzung (SQLite ganz ohne zweite Schicht) | niedrig | `TargetConnections::open()` öffnet nur lesend, wenn das angemeldete Konto nicht `write` darf | ganze Suite grün |
| N-07 Abbruch-Merker vor der Besitzprüfung | niedrig | erst prüfen, dann merken | `QueryRunnerTest::test_a_run_of_another_account_cannot_be_cancelled` |
| I-01 Servermeldungen unmaskiert | Hinweis | Aufträge maskieren jetzt (`Masking::exception`); Raster und Editor zeigen die Meldung des Servers bewusst — sie gehört dem Konto, das die Abfrage stellt | — |
| I-05 Vorschau ohne Recht «schreiben» | Hinweis | Vorschau verlangt `write` | — |
| I-07 Systemprüfung ohne Cookie- und Host-Hinweise | Hinweis | `phpdbadmin:check` warnt bei fehlendem `SESSION_SECURE_COOKIE` in Produktion und bei erlaubtem Server der App-Datenbank | — |
| I-02, I-03, I-04, I-06 | Hinweis | offen, bewusst: Entwicklungswerkzeuge nur in `local`; CSV-Export ohne Formelschutz (Daten bleiben unverändert, in der Hilfe zu dokumentieren); erlaubte Hosts ohne Ports und Verbindungsaufbau nur mit Zeitlimit des Treibers; Hilfeseite mit `v-html` nur für mitgelieferten Text | — |

**Nicht geprüft** im ersten Durchgang war die KI-Hilfe (Q-08) und die Sitzungsliste (A-08), die während der Prüfung entstanden: Schlüssel verschlüsselt und `$hidden`, nur Struktur im Umriss (Test mit Attrappe: keine Datenzeile im Auftrag), `Gate::authorize('view')`, Schema gegen die Liste geprüft, Protokolleintrag je Anfrage; Sitzungskennung nur als HMAC im Browser. Ebenso die nach der Prüfung zusammengeführten Timeboxen 05 und 10–11 — sie folgen denselben Mustern (Policy, `withWritable`, Bezeichner-Quoting, Protokoll) und werden vor der Freigabe von v1.1/v1.2 in einem zweiten Durchgang geprüft.

## Zweiter Durchgang (TB 05, 10a, 10b, 11, 12)

Ein zweiter, ebenfalls nur lesender Durchgang über alles, was nach dem ersten dazukam (Struktur ändern, Objekte und Diagramm, EXPLAIN, Ergebnisse bearbeiten, Suchen und Ersetzen, Sicherung, Excel, Schemavergleich, Laravel-Migration, Herd, SSH-Tunnel, Sitzungen, KI-Hilfe). **Kein hoher Befund**; mit Proben belegt, dass die erzeugte Migration keinen PHP-Code einschleust, `ssh` keine Optionen aus Feldern annimmt und Befehle aus hochgeladenen Sicherungen nicht auf dem App-Server laufen.

| Befund | Schwere | Behebung | Nachweis |
|---|---|---|---|
| M-1 Werte einer Aufzählung mit «'» (oder ein Spaltenname mit «"») brechen im Änderungsskript des Vergleichs und in der erzeugten Migration aus dem SQL-Literal aus (Laravels `enum()` verdoppelt «'» nicht) | mittel | solche Spalten werden Text mit Hinweis statt Aufzählung (`LaravelTypes::enumOrText`) | `LaravelTypesTest` (4) |
| M-2 Wiederherstellung prüft Recht und Schreibschutz beim Ausführen nicht erneut (Rückfall von M-02) | mittel | `TransferRunner::authorize()` behandelt Wiederherstellung wie Import; `BackupRunner::restore()` ruft `assertWritable()` | `BackupControllerTest::test_restore_queued_before_write_access_ended_writes_nothing` (ohne Fix rot) |
| M-3 Schlüsseldateien im erlaubten SSH-Ordner kann jedes Konto benutzen | mittel | nur Administratoren tragen Schlüsseldateien ein; eine gespeicherte Verbindung benutzt sie nur, wenn sie einer Administratorin gehört | `ConnectionControllerTest::test_a_member_cannot_use_a_key_file_from_the_server` |
| N-1 App-Datenbank hinter einem Tunnel per Name erreichbar | niedrig | `guardDatabase` vergleicht auch Namen und deren Auflösung | `HostPolicyTest::test_the_app_database_is_refused_behind_a_tunnel_by_name` |
| N-2 «Host-Schlüssel vergessen» gilt für alle Verbindungen zum selben SSH-Server | niedrig | nutzen andere Konten denselben Server, nur noch für Administratoren | `SshHostKeyControllerTest` (2) |
| N-3 über den Schlüsselpfad lässt sich prüfen, welche Dateien es gibt | niedrig | entfällt mit M-3 — nur Administratoren | — |
| N-4 Werte aus der Sicherungsdatei (psql `CONTEXT: COPY …, line …`) und Pfade der Zwischendatei im Protokoll | niedrig | Muster in `Masking::message`, Pfade auf den Dateinamen gekürzt | `MaskingTest::test_copy_context_of_psql_keeps_no_value` |
| Hinweise | — | geerbte `PGHOSTADDR`/`PGPASSFILE`/`PGTARGETSESSIONATTRS` werden für die Werkzeuge gelöscht; offen bleiben: Steuerzeichen in PostgreSQL-Namen machen das SVG ungültig (kein XSS), Suchtext der Schema-Suche in der Adresse, `sqlite3` ohne `-safe` (`.restore` braucht Dateizugriff), MySQL-Sicherung ohne Prüfung der App-Datenbank (Restrisiko wie M-01) | — |

## Anhang: Bericht der Prüfung (Stand `58f2f72`, vor der Behebung)

**Datum:** 29.10.2026 · **Prüfer:** Sicherheitsdurchgang (White-Box, Quellcode + lokale Proben)
**Geprüfter Stand:** Arbeitskopie `das Repository`, HEAD **58f2f72** («TB 08: Sprunglink zum Inhalt …»). Der Auftrag nannte b43bf31; der Hauptstrang hat während der Prüfung vier TB-08-Commits nachgelegt (Installationsbefehl, Systemprüfung, Hilfeseite, Sprunglink). Geprüft wurde die Arbeitskopie, also der neuere Stand — die neuen Dateien (`HelpController`, `SystemCheck`, `InstallCommand`) sind mit drin.
**Umfang:** `routes/*`, `app/**`, `config/*`, `bootstrap/app.php`, `resources/js/**`, `resources/views/app.blade.php`, `.env.example`, Tests unter `tests/`. Dazu Wegwerf-Proben unter `(Wegwerf-Probe, nicht im Repository)` gegen die Herd-Dienste (MariaDB 3306, MySQL 3307, PostgreSQL 5432). **Keine Änderung im Repository**, keine Migration gegen die App-Datenbank, keine Browser-Werkzeuge. `.env` wurde nicht ausgegeben.

---

### 1. Kurzfazit

**Es gibt einen hohen Befund.** Die Abnahme «Prüfbericht nach OWASP ohne hohen Befund» ist damit **noch nicht erreicht**.

* **1 × hoch:** Über den **Datenbanknamen** lassen sich dem Verbindungsaufbau zusätzliche DSN-Parameter unterschieben (`host=`, `port=`). Damit baut phpdbadmin Verbindungen zu **beliebigen Hosts** auf — die erlaubten Hosts (V-05, T-05) sind wirkungslos. Belegt mit einer Probe auf allen drei Serverdialekten; bei PostgreSQL entsteht eine voll nutzbare Sitzung, bei MySQL/MariaDB eine authentifizierte Sitzung mit frei wählbarem Datenbanknamen.
* **3 × mittel:** App-Datenbank nur bei SQLite geschützt (T-17); Schreibschutz und Recht werden beim Ausführen eines Hintergrund-Auftrags nicht erneut geprüft, und die Importer heben die Sitzungs-Schicht selbst auf (T-06); die Drosselung der Code-Prüfung hängt an der Sitzungskennung und ist damit umgehbar (T-01).
* **7 × niedrig**, **7 × Hinweis** — darunter die fehlenden Sicherheits-Header/CSP (zweite Hälfte von T-14) und die fehlende CSRF-Probe mit echter Middleware (T-15).

Alles andere hält: Zugriffskontrolle je Verbindung, Bezeichner-Quoting, Verschlüsselung der Zugangsdaten, Maskierung im Protokoll, Grenzen gegen DoS, keine XSS-Senke für Datenbankinhalte. `composer audit` und `npm audit --omit=dev`: **keine bekannten Lücken**.

---

### 2. Bedrohungen T-01 bis T-19

| Nr. | Status | Beleg (Datei:Zeile · Test) | Lücke |
|---|---|---|---|
| T-01 Konto übernommen | **teilweise** | `routes/auth.php:21–25` (`throttle:login-code`, `throttle:login-verify`), `app/Providers/FortifyServiceProvider.php:39–56`, `app/Services/Auth/LoginCodes.php:17–70` (6 Ziffern, HMAC, einmalig, 5 Fehlversuche), `LoginController.php:88–92` (`Auth::login` + `session()->regenerate()`) · `LoginControllerTest::test_code_signs_in_with_new_session_and_records_login`, `…::test_throttles_code_requests_per_address_after_five_per_minute`, `LoginCodesTest`, `PasskeyLoginControllerTest` | Limiter `login-verify` schlüsselt auf `session()->getId()` — vom Angreifer frei erneuerbar (Befund M-03). Kein Lockout je Adresse. |
| T-02 Passwort-Endpunkte | **umgesetzt** | `FortifyServiceProvider.php:24` (`Fortify::ignoreRoutes()`), `config/fortify.php:165–169` (nur `Features::passkeys`) · `StarterKitRemovalTest::test_removed_address_is_not_found` (17 Adressen), `…::test_password_login_is_not_accepted` | — |
| T-03 Zugangsdaten im Browser | **umgesetzt** | `app/Models/DatabaseConnection.php:50` `#[Hidden(['password'])]`, `ConnectionController.php:85–100` (Formular zeigt nur `hasPassword`), `HandleInertiaRequests.php:56–66` · `ConnectionControllerTest::test_overview_never_contains_passwords`, `…::test_edit_page_shows_whether_password_is_stored_but_never_the_password` | — |
| T-04 Klartext auf der Platte | **umgesetzt** | `DatabaseConnection.php:72` (`'password' => 'encrypted'`), `TargetConnections.php:110` (`Crypt::encryptString` in der Sitzung) · `ConnectionControllerTest::test_stores_server_connection_with_encrypted_password`, `TargetConnectionsTest::test_without_stored_password_opens_only_after_it_was_given_for_the_session` | — |
| T-05 SSRF / erlaubte Hosts | **fehlt (umgangen)** | `HostPolicy.php:47–76` (einmal auflösen, geprüfte Adresse verbinden), `ValidatesConnectionSettings.php:63–73` · `HostPolicyTest` (7 Fälle), `ConnectionControllerTest::test_rejects_host_outside_allowed_list` | **H-01:** Der Datenbankname geht ungefiltert in den DSN; `host=`/`port=` darin überschreiben die geprüfte Adresse. Die Prüfung greift nur für das Feld «Host». |
| T-06 Schreiben trotz Schreibschutz | **teilweise** | `ConnectionFactory.php:33–75` (Init-Befehl, `OPEN_READONLY`, Authorizer), `MySqlDialect.php:212–223`, `PostgresDialect.php:301–319`, `SqliteDialect.php:178–182`, `WriteUnlock.php` (Freigabe an die Sitzung gebunden) · `DialectContractTest::test_read_only_guard_withstands_escape_attempts` (5–12 Versuche je Treiber), `TargetConnectionsTest::test_read_only_connection_rejects_every_write`, `…::test_session_protections_survive_a_reconnect`, `…::test_writable_connection_is_refused_for_read_only_connection` | **M-02:** `TransferRunner::execute()` prüft `read_only` und das Recht nicht erneut; `CsvImporter.php:157`, `SqlImporter.php:117`, `Statistics.php:23` setzen auf der Verbindung aus `open()` selbst `set session transaction read write` — damit fällt die Sitzungs-Schicht (Probe belegt). Für SQLite gibt es ohne `read_only`-Flag gar keine Schicht (N-06). |
| T-07 Mehrfachanweisungen | **umgesetzt** | `MySqlDialect.php:40` (`ATTR_MULTI_STATEMENTS => false`), `ConnectionFactory` ohne `unprepared()` für Eingaben · `DialectContractTest::test_multiple_statements_in_one_call_are_refused` | SQL-**Import** führt bewusst ganze Skripte aus (`SqlImporter.php:142`, `exec()` je zerlegter Anweisung) — nur mit Recht «schreiben», so gewollt. |
| T-08 SQLite `ATTACH` | **umgesetzt** | `ConnectionFactory.php:150–175` (`setAuthorizer`, nur erlaubte Ordner, `''`/`:memory:` erlaubt), `HostPolicy.php:78–110` (`realpath`, App-Datenbank nie) · `DialectContractTest::test_sqlite_attach_only_inside_allowed_folder`, `…::test_sqlite_vacuum_and_attach_without_file_are_allowed`, `TargetConnectionsTest::test_session_protections_survive_a_reconnect`, `HostPolicyTest::test_refuses_sqlite_path_outside_allowed_folders` | — |
| T-09 Konto mit Dateirechten | **umgesetzt** | `MySqlDialect.php:232–250`, `PostgresDialect.php:328–350` (`rolsuper`, `FILE`, `secure_file_priv`), Anzeige über `account_warnings` (`WorkspaceController.php:133`) · `DialectContractTest::test_warns_about_root_account_but_not_about_read_account`, `ConnectionControllerTest::test_overview_shows_stored_account_warnings_in_the_language_of_the_account` | Warnung, keine Sperre — so im Bedrohungsmodell vorgesehen (Restrisiko «Der Server ist die Grenze»). |
| T-10 `LOAD DATA LOCAL INFILE` | **umgesetzt** | `MySqlDialect.php:41` (`ATTR_LOCAL_INFILE => false`) · `DialectContractTest::test_mysql_never_uploads_files_of_the_app_server` | — |
| T-11 Daten in Meldungen/Protokoll | **teilweise** | `bootstrap/app.php:41–52` (QueryException maskiert ins Log, ersetzt den Standardeintrag), `Masking.php` (SQL, Meldungen, Bindungen), `AuditLog.php:28–60`, `ServerPlan.php:50–56` (Passwörter) · `MaskingTest` (Unit), `AuditLogTest`, `DialectServerContractTest::test_preview_masks_the_password_and_quotes_names_that_look_like_sql` | Meldungen des Servers gehen unmaskiert an die Oberfläche (`QueryRunner.php:172`, `TableData.php:461`) — anders als `RowChangeController` (I-01). `query_history.sql` speichert die Anweisung im Klartext (N-05). |
| T-12 lange Abfragen | **umgesetzt** | `config/phpdbadmin.php` (`statement_timeout_ms`, `editor.timeout_seconds`), `Dialect::setStatementTimeout`, `QueryRunner.php:238–300` (SQLite im Arbeitsprozess, SIGKILL), Abbrechen über eigene Verbindung (`QueryRunner.php:104–135`) · `DialectContractTest::test_statement_timeout_stops_long_query_within_two_seconds`, `…::test_sqlite_says_it_has_no_statement_timeout`, `QueryRunnerTest` | Beim Verbinden mit falschem Protokoll greift `PDO::ATTR_TIMEOUT` nicht (Probe: 120 s, I-04). |
| T-13 riesige Ergebnisse | **umgesetzt** | `editor.row_limit`/`max_row_limit`, `ResultReader`, `GridQuery.php:75` (`limit` max 500), `RowEditor::MAX_CHANGES = 1000`, Export in Paketen (`TableReader`, Server-Cursor), `transfers.sync_max_rows/_bytes` · `QueryRunnerTest`, `LargeTransferTest`, `GridPerformanceTest` | — |
| T-14 XSS über Datenbankinhalte | **teilweise** | Kein `v-html`/`innerHTML` für Datenbankinhalte (einzige Stelle: `resources/js/pages/help/Show.vue:49`, mitgelieferter Markdown-Text, `html_input => strip`), Blade escapt (`app.blade.php:10`) | **Kein CSP** und keine weiteren Sicherheits-Header (N-01) — die zweite Hälfte des Gegenmittels fehlt. |
| T-15 CSRF | **teilweise** | `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` in der `web`-Gruppe (geprüft über die Middleware-Konfiguration), Cookies verschlüsselt ausser `appearance`, `samesite=lax` | **Kein Test mit echter Middleware** (N-03) — das Bedrohungsmodell verlangt genau das. |
| T-16 fremde Verbindung | **umgesetzt** | `AppServiceProvider.php:75–90` (`{connection}` → `visibleTo()->findOrFail()` ⇒ 404), `DatabaseConnection.php:120–147`, Policy `view/write/manage/share`, `TransferWorkspaceController::ownTransfer` (404), `SqlController.php:194` (gespeicherte Abfrage), `RowEditor::undo` (nach `user_id`+`connection_id`), `AuditController` nur `can:administrate` · `ConnectionControllerTest::test_returns_404_for_connection_of_other_account`, `…::test_admin_sees_every_shared_connection_but_no_personal_connection_of_others`, `DatabaseConnectionPolicyTest`, `TransferControllerTest`, `SqlControllerTest`, `ImportControllerTest` | — |
| T-17 phpdbadmin verwaltet sich selbst | **teilweise** | `HostPolicy.php:29–38`: der Schutz greift **nur**, wenn die App-Datenbank SQLite ist | **M-01:** Mit MySQL/MariaDB/PostgreSQL als App-Datenbank ist sie als Zielverbindung erreichbar. |
| T-18 Probe-Seiten | **umgesetzt** | `routes/` enthält nur `web.php`, `auth.php`, `console.php`; keine `probe.php`, keine `/probe/*`-Route (`php artisan route:list`) | Dev-Werkzeuge der Pakete sind in `local` offen: `_boost/browser-logs` ohne Middleware, Inertia-DevTools ohne Anmeldung (I-02). |
| T-19 Ersteinrichtung | **umgesetzt** | `SetupKey.php` (Datei 0600 in `storage/app/private`, Ordner 0700, nie überschrieben, `hash_equals`, nach Gebrauch gelöscht), `FirstSetupController.php:38–75` (nur ohne Konto, `Cache::lock`, `setup.failed` ins Protokoll), `routes/auth.php:33` (`throttle:login-verify`), Konsolenweg mit signiertem Link (`InstallCommand`, `SetupController::isOpen`) · `SetupKeyTest`, `FirstSetupControllerTest`, `SetupControllerTest`, `InstallCommandTest` | — |

---

### 3. Befunde

### HOCH

#### H-01 · DSN-Injektion über den Datenbanknamen umgeht die erlaubten Hosts (SSRF)

**Ort:** `app/Http/Requests/Connections/ValidatesConnectionSettings.php:34` (Regel für `database`), `app/Dialects/PostgresDialect.php:34`, `app/Dialects/MySqlDialect.php:58`, `app/Connections/ConnectionFactory.php:33–48`; Senke: `vendor/laravel/framework/src/Illuminate/Database/Connectors/PostgresConnector.php:74` (`dbname='…'`) bzw. `MySqlConnector.php:80–84` (`;dbname=…`).

**Szenario (Eingabe → Wirkung).** Jedes angemeldete Konto darf eine Verbindung anlegen (`connections.store` ohne weitere Rechte) und «Verbindung testen» aufrufen. Der Host wird gegen `PHPDBADMIN_ALLOWED_HOSTS` geprüft, der **Datenbankname** nur auf `string|max:255`. Er landet unverändert im DSN:

* PostgreSQL: `database = postgres' host='10.1.2.3' port='5432` → `pgsql:host=<geprüft>;dbname='postgres' host='10.1.2.3' port='5432'…` — libpq nimmt den zuletzt gesetzten Parameter.
* MySQL/MariaDB: `database = ;host=10.1.2.3;port=3306;dbname=kundendaten` → `mysql:host=<geprüft>;port=…;dbname=;host=10.1.2.3;port=3306;dbname=kundendaten`.

**Belegt** (`(Wegwerf-Probe, nicht im Repository)`, `dsn-mysql.php`, `dsn-mysql2.php`, `validierung.php`), erlaubt war jeweils nur `192.0.2.10` (nicht erreichbar):

```
PostgreSQL   VERBUNDEN (0.01s) -> inet_server_addr 127.0.0.1 / current_database postgres
MariaDB      ;host=127.0.0.1;port=9;dbname=x    → (0.00s) [2002] Connection refused     (Kontrolle 192.0.2.10: 2.00s Timeout)
MariaDB      ;host=127.0.0.1;port=3307;dbname=x → (0.00s) [1049] Unknown database 'x'    (echter MySQL-Server, Auth durchlaufen)
Validierung  beide Nutzlasten gehen unverändert durch TestConnectionRequest::rules()
```

Damit ist T-05/V-05 vollständig aushebelbar: Verbindungen in das interne Netz (auch auf Ports, die der erlaubte Host nie hatte), Port- und Dienst-Erkennung über die unterschiedlichen Fehlermeldungen, und bei PostgreSQL eine voll nutzbare Sitzung inklusive Datenraster, SQL-Editor und Export. Zusätzlich lassen sich bei PostgreSQL weitere libpq-Parameter setzen (z. B. `sslmode`, `connect_timeout`) — die SSL-Wahl der Oberfläche wird so übergangen. Die eingetragenen Zugangsdaten gehen an den selbst gewählten Host.

**Behebung.** Den Datenbanknamen (und jedes andere Feld, das in einen DSN wandert) als Bezeichner prüfen, nicht als freien Text — z. B. Regel `regex:/^[^\x00-\x1F;\'"\\\\]+$/u` plus Verbot von `=`; sauberer: den DSN nicht aus Text zusammensetzen, sondern bei PostgreSQL `dbname` über die Verbindungs-URI korrekt quoten bzw. nach dem Aufbau prüfen, dass die Gegenstelle die geprüfte Adresse ist. Empfehlung zusätzlich als zweite Schicht: nach dem Verbinden `inet_server_addr()` bzw. `@@hostname`/`SHOW VARIABLES LIKE 'port'` gegen die geprüfte Adresse halten und sonst trennen. Gleiches Muster für `sqlite_path` bleibt unverändert (dort greift `realpath`).

**Test.** `ConnectionControllerTest`/`TestConnectionControllerTest`: Datenbankname mit `;host=`, `' host='`, `=`, `;`, NUL und Zeilenumbruch wird mit Fehler am Feld `database` abgewiesen (je Treiber); `TargetConnectionsTest`: `ConnectionFactory::make()` mit solcher Eingabe wirft `ConnectionNotAllowed` statt zu verbinden — als Vertragstest gegen die drei Server (mit erlaubtem Host `192.0.2.10` und injiziertem `127.0.0.1`, wie in der Probe).

---

### MITTEL

#### M-01 · Die eigene App-Datenbank ist als Zielverbindung erreichbar, sobald sie nicht SQLite ist (T-17)

**Ort:** `app/Connections/HostPolicy.php:29–38`.

**Szenario.** `fromConfig()` setzt `appDatabase` nur bei `database.default === 'sqlite'`; geprüft wird dieser Wert ausserdem nur in `resolveSqlitePath()`. Läuft die Installation mit MariaDB/MySQL/PostgreSQL als App-Datenbank (in `.env.example` nur kommentiert, im Betrieb aber eine naheliegende Wahl, und `SystemCheck` unterstützt sie ausdrücklich), legt **jedes** Konto — auch ein Mitglied mit nur einer eigenen Verbindung — eine Verbindung auf `127.0.0.1` zur Datenbank von phpdbadmin an (Host ist in der Vorgabe erlaubt, Zugang in Herd `root` ohne Passwort). Danach: `update users set role = 'admin' where …` über das Datenraster oder den SQL-Editor, Sitzungen stehlen (`sessions`), Protokolleinträge löschen (`audit_log`), verschlüsselte Passwörter aller Verbindungen abziehen. Rechteausweitung bis Administrator und Umgehung des Protokolls.

**Behebung.** Den Vergleich treiberübergreifend ziehen: beim Speichern **und** bei jedem Verbinden Host (aufgelöste Adresse), Port und Datenbankname gegen `config('database.connections.'.config('database.default'))` halten und ablehnen («Die eigene Datenbank von phpdbadmin ist hier nicht erreichbar.»). Für «alle Datenbanken» zusätzlich beim Schema-/Datenbankwechsel prüfen. Zu H-01 hin wirkt dieselbe Prüfung nur, wenn der DSN nicht mehr manipulierbar ist — beide Befunde gemeinsam beheben.

**Test.** `TargetConnectionsTest`: mit `DB_CONNECTION=mysql` (bzw. `pgsql`) auf die App-Datenbank zeigende Einstellungen → `ConnectionNotAllowed` mit `ConnectionProblem::APP_DATABASE`; `ConnectionControllerTest`: `store`/`update` antwortet mit Fehler am Feld `database`.

#### M-02 · Schreibschutz und Recht werden beim Ausführen eines Auftrags nicht erneut geprüft — und der Importer hebt die Sitzungs-Schicht selbst auf

**Ort:** `app/Services/Transfer/TransferRunner.php:133–175` (`execute()` ohne Rechte-/`read_only`-Prüfung), `app/Services/Transfer/CsvImporter.php:157`, `app/Services/Transfer/SqlImporter.php:117`, `app/Services/Transfer/Statistics.php:23` (`set session transaction read write` auf der Verbindung aus `TargetConnections::open()`), `app/Connections/TargetConnections.php:41`.

**Szenario.** Geprüft wird nur in der Anfrage (`TransferWorkspaceController::authorizeWrite()`). Der Auftrag läuft später: `RunImport` → `TransferRunner::execute()` → `targets->open()`. Wird die Verbindung zwischen Einreihen und Ausführen auf «schreibgeschützt» gestellt (oder dem Konto das Recht «schreiben» entzogen, oder die Freigabe geteilt/entzogen), läuft der Import trotzdem — `open()` liefert zwar eine schreibgeschützte Sitzung, aber der Importer setzt sie mit `set session transaction read write` selbst zurück. Dass diese Anweisung die Schicht tatsächlich öffnet, ist belegt (`(Wegwerf-Probe, nicht im Repository)`):

```
MariaDB: INSERT ohne Trick abgewiesen — SQLSTATE[25006] 1792
MariaDB: INSERT nach «set session transaction read write» GELUNGEN -> Schutzschicht umgangen
MySQL:   dito
```

Bei PostgreSQL und SQLite hält die Schicht in diesem Fall (kein `read write` für diese Treiber bzw. `OPEN_READONLY`), dort scheitert der Auftrag mit 25006. Das Zeitfenster und die fehlende Nachprüfung sind aus dem Code belegt; ein Ende-zu-Ende-Lauf über die Warteschlange wurde **nicht** gefahren (als «Zeitfenster» zu lesen, nicht als trivial auslösbar).

**Behebung.** (1) In `TransferRunner::execute()` vor dem Start erneut prüfen: Auftrag gehört zu einem aktiven Konto, Konto hat `write` an der Verbindung, Verbindung ist nicht `read_only` — sonst Auftrag als «abgebrochen» beenden. (2) Schreibende Dienste die Verbindung über `TargetConnections::withWritable()` holen lassen (wirft `ReadOnlyConnection`) statt über `open()` mit anschliessendem `set session transaction read write`; dann braucht es die Anweisung gar nicht. (3) `Statistics::refresh()` genauso.

**Test.** `TransferRunnerTest`: Auftrag in `Queued`, danach `read_only = true` setzen bzw. Recht entziehen → `execute()` schreibt nichts, Auftrag endet als `cancelled`/`failed`; Vertragstest in `DialectContractTest`: `set session transaction read write` auf einer `readOnly`-Verbindung **ausserhalb** von `runReadOnly` darf keinen Schreibzugriff erlauben (ergänzt die bestehende Probe, die jeden Versuch in `runReadOnly` kapselt und die Lücke deshalb nicht sieht).

#### M-03 · Drosselung der Code-Prüfung hängt an der Sitzungskennung (T-01)

**Ort:** `app/Providers/FortifyServiceProvider.php:52–55` (`Limit::perMinute(10)->by('login-verify:'.$request->session()->getId().'|'.$request->ip())`), `app/Models/LoginCode.php:52–58`, `app/Services/Auth/LoginCodes.php:38–70`.

**Szenario.** Die Anmeldung kennt nur einen Faktor: den sechsstelligen Code. Die Sitzungskennung ist clientseitig beliebig erneuerbar (Cookie löschen) — der Limiter `login-verify` greift damit nicht mehr. Begrenzend bleibt nur `login-code` (5/min je Adresse+IP, 20/min je IP) sowie die 5 Fehlversuche je Code. Rechnung: 5 neue Codes/min × 5 Versuche = 25 Rateversuche/min je Adresse ⇒ ~1'500/h ⇒ ~36'000/Tag gegen 10⁶ Möglichkeiten ≈ 3,5 % Trefferwahrscheinlichkeit pro Tag und Adresse, mit mehreren IPs mehr. Ein Konto-Lockout je Adresse gibt es nicht; jeder Versuch ist allerdings im Protokoll (`auth.failed`), und das Opfer bekommt die Mails (auffällig). Gerechnet, nicht durchprobiert.

**Behebung.** Zweiten Limiter ohne Clienteinfluss ergänzen, z. B. `Limit::perMinute(10)->by('login-verify:'.Fingerprint::ofEmail($emailAusDerSitzung))` plus `Limit::perHour(30)` je Adresse; zusätzlich nach n Fehlversuchen je Adresse eine Sperrminute (wie Basalto) oder den Code auf 8 Zeichen aus `SetupKey::ALPHABET` verlängern.

**Test.** `LoginControllerTest`: nach fünf falschen Codes mit jeweils **neuer Sitzung** (`$this->flushSession()` / neuer Testclient) antwortet `login.code.verify` mit 429 bzw. der Drosselmeldung; Protokolleintrag je Versuch bleibt.

---

### NIEDRIG

#### N-01 · Keine Sicherheits-Header, kein CSP (zweite Hälfte von T-14)

**Ort:** `bootstrap/app.php:24–34` (keine Header-Middleware), Antwort von `https://phpdbadmin.test/login` geprüft: nur `cache-control`, `link`, `set-cookie` — **kein** `content-security-policy`, `x-frame-options`/`frame-ancestors`, `referrer-policy`, `x-content-type-options`, `strict-transport-security`.

**Szenario.** Eine künftige XSS-Senke (z. B. die neue Hilfeseite mit `v-html`, oder eine Bibliothek) wirkt ohne CSP voll; die Oberfläche lässt sich in einen fremden Rahmen setzen (Clickjacking auf «Importieren», «Anwenden», «Prozess beenden»); `Referer` trägt Verbindungs-, Schema- und Tabellennamen nach draussen. Die Cookies sind in Ordnung (`secure; httponly; samesite=lax` — `session.secure = null` erbt bei HTTPS automatisch `secure`).

**Behebung.** Eine kleine Middleware in der `web`-Gruppe: `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'` (das Inline-Skript in `app.blade.php:12–22` braucht eine Nonce oder wandert in eine Datei), dazu `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` und in Produktion HSTS. `SESSION_SECURE_COOKIE=true` in die Produktionsdoku.

**Test.** Feature-Test «jede Antwort trägt CSP und `frame-ancestors 'none'`» auf `/login` und einer Arbeitsbildschirm-Seite; Sichtprüfung der Konsole auf CSP-Verstösse.

#### N-02 · `'serve' => true` öffnet signierte Lese- und Schreibrouten auf `storage/app/private`

**Ort:** `config/filesystems.php:33–39`; daraus registriert Laravel `GET storage/{path}` (`storage.local`) und `PUT storage/{path}` (`storage.local.upload`) — **ohne** `web`/`auth`, nur mit signierter URL (`vendor/.../Filesystem/ServeFile.php:27`, `ReceiveFile.php:27`).

**Szenario.** phpdbadmin nutzt `temporaryUrl()` nirgends (geprüft); die Routen sind reine Angriffsfläche über dem privaten Speicher — Export-Dateien fremder Konten, hochgeladene Importdateien und `einrichtungsschluessel.txt`. Wer je eine signierte URL in die Hand bekommt (Protokoll, Proxy-Log, geleakter `APP_KEY`), liest und **schreibt** ohne Anmeldung in diesen Ordner.

**Behebung.** `'serve' => false` für die Disk `local` setzen (Downloads laufen über `TransferController::download` mit Rechteprüfung).

**Test.** Feature-Test: `GET /storage/transfers/…` ohne Signatur → 404, und die Route existiert nach der Änderung nicht mehr (`Route::has('storage.local')` ist falsch).

#### N-03 · Keine CSRF-Probe mit echter Middleware (T-15)

**Ort:** `tests/` — kein Treffer für `PreventRequestForgery`/`csrf`; Laravels Testkern schaltet die Prüfung in Tests ab, die bestehenden Tests belegen T-15 also nicht.

**Szenario.** Rein eine Nachweislücke: ein künftiges `withoutMiddleware`, eine `except`-Liste oder eine API-Route fiele niemandem auf. Die Middleware ist derzeit vorhanden und aktiv.

**Behebung/Test.** Ein Test, der die Middleware ausdrücklich einschaltet (`$this->withMiddleware(PreventRequestForgery::class)` bzw. `Route::middleware('web')`-Test) und für eine schreibende Route (`POST /c/{id}/unlock`, `POST /c/{id}/transfers/import`) ohne Token 419 erwartet, mit Token 2xx/302.

#### N-04 · Einladung meldet in ein bestehendes Konto an

**Ort:** `app/Http/Controllers/Auth/InvitationController.php:44–57` (`Invitation::findByToken` → `User::firstOrCreate` → `Auth::login`).

**Szenario.** Beim Einladen verhindert `Rule::unique('users','email')` doppelte Konten, und `resend` prüft zusätzlich. `accept` prüft es nicht: Eine Einladung, die vor dem Entstehen des Kontos verschickt wurde (z. B. zwei Einladungen hintereinander, oder Konto über `phpdbadmin:install`/zweite Einladung entstanden), bleibt bis zu 7 Tage ein **zweiter Anmeldeweg ohne Code und ohne Passkey** — wer die Mail liest (altes Postfach, weitergeleitete Mail, Archiv), landet im bestehenden Konto, inklusive Administratorrechten, wenn die Einladung so lautete.

**Behebung.** In `accept()` ablehnen, wenn zur Adresse schon ein Konto existiert, dessen `created_at` vor `invitation.created_at` liegt (bzw. generell, wenn `User` existiert und `invitation.accepted_at === null` nicht aus demselben Vorgang stammt) — und offene Einladungen beim Anlegen eines Kontos derselben Adresse automatisch verfallen lassen.

**Test.** `InvitationControllerTest`: Einladung ausstellen, Konto derselben Adresse anderweitig anlegen, dann Einladung annehmen → Fehlermeldung, `assertGuest()`.

#### N-05 · Verlauf und gespeicherte Abfragen halten die Anweisung im Klartext

**Ort:** `app/Http/Controllers/Workspace/SqlController.php:142–150` (`QueryHistoryEntry` mit `$result['sql']`), `…:178–185` (`SavedQuery`), `app/Models/QueryHistoryEntry.php:35–38` (90 Tage).

**Szenario.** Wer im SQL-Editor `CREATE USER 'app'@'%' IDENTIFIED BY 'geheim'` oder ein `INSERT` mit Personendaten ausführt, hat den Wert 90 Tage im Klartext in der App-Datenbank — im Protokoll wird genau das maskiert (`AuditLog` → `Masking::sql`). Ein Zugriff auf die App-Datenbank (siehe M-01) oder ein Backup davon gibt diese Werte her. Nur das eigene Konto sieht den Verlauf (geprüft), Administratoren nicht.

**Behebung.** Entweder bewusst dokumentieren (der Verlauf soll wieder ausführbar sein) **und** die Aufbewahrung kurz halten, oder den Verlauf maskiert speichern und den Originaltext nur in der Sitzung halten. Mindestens: im Datenmodell/Doku als «enthält Werte» kennzeichnen, damit Backups entsprechend behandelt werden.

**Test.** `SqlControllerTest`: Nach `CREATE USER … IDENTIFIED BY …` enthält `query_history.sql` kein Passwort (bei Variante «maskiert speichern»).

#### N-06 · SQLite: Schreibschutz-Schicht nur am Flag, nicht am Recht

**Ort:** `app/Connections/TargetConnections.php:41`.

**Szenario.** `open()` öffnet die Datei nur dann mit `OPEN_READONLY`, wenn die **Verbindung** als schreibgeschützt markiert ist — unabhängig davon, ob das Konto «schreiben» darf. Für ein Konto mit nur «ansehen» hängt der Schutz damit allein an den Controllern (die ihn konsequent prüfen, geprüft); bei SQLite gibt es keine zweite Schicht wie die Nur-Lese-Transaktion der Server. Kein Ausbruchsweg gefunden — ein freies `WHERE` (`TableData.php:213`) kann in SQLite nichts schreiben.

**Behebung.** `open()` zusätzlich read-only öffnen, wenn das Konto an dieser Verbindung kein `write` hat (Gate abfragen bzw. Recht übergeben).

**Test.** `TargetConnectionsTest`: Konto mit Recht «ansehen» → `open()` auf einer nicht schreibgeschützten SQLite-Verbindung weist `insert` ab.

#### N-07 · Abbruch-Merker wird vor der Besitzprüfung gesetzt

**Ort:** `app/Services/Sql/QueryRunner.php:106–118`.

**Szenario.** `cancel()` schreibt `sql-abbruch:<runId>` in den Cache, **bevor** geprüft wird, ob der Lauf dem Konto gehört. Wer eine fremde Laufkennung kennt, lässt den fremden Lauf als «abgebrochen» enden (weitere Anweisungen werden übersprungen). Die Kennung ist `crypto.randomUUID()` (`resources/js/pages/workspace/Sql.vue:126–130`), also praktisch nicht zu erraten; im Fallback (kein `crypto`) ist sie aus Zeit und `Math.random()` gebaut.

**Behebung.** Erst den Lauf aus dem Cache lesen und Konto sowie Verbindung prüfen, dann den Merker setzen; Fallback-Kennung serverseitig vergeben.

**Test.** `SqlControllerTest`: zweites Konto ruft `sql/cancel` mit der Laufkennung des ersten → kein Merker, Lauf des ersten Kontos bleibt «ok».

---

### HINWEIS

* **I-01 · Servermeldungen unmaskiert in der Oberfläche.** `QueryRunner.php:172` und `TableData.php:461` geben die Meldung des Servers unverändert zurück (z. B. «Duplicate entry 'kunde@example.ch'»), während `RowChangeController.php:140` sie über `Masking::exception()` schickt. Die Meldung sieht nur, wer die Verbindung ohnehin lesen darf — Auswirkung gering, aber uneinheitlich zu T-11. Einheitlich maskieren oder in der Doku festhalten, warum hier nicht.
* **I-02 · Dev-Werkzeuge in `local`.** `POST /_boost/browser-logs` hat **keine** Middleware (kein `web`, kein `auth`) und schreibt beliebigen Text in den Log-Kanal `browser` (Log-Verschmutzung, Platzverbrauch); `GET /_inertia/devtools/entries` ist in `local` ohne Anmeldung lesbar (`vendor/inertiajs/inertia-laravel/src/DevTools/Http/Authorize.php:26`) und enthält aufgezeichnete Props. Beides nur in `local` bzw. nur mit Dev-Abhängigkeiten (Boost ist `require-dev`), aber auf einem geteilten Netz (Herd im WLAN) offen. `inertia.devtools.enabled => false` ausserhalb der eigenen Maschine bzw. ein Gate setzen.
* **I-03 · CSV-Export neutralisiert Formelzeichen nicht.** `Values::csv`/`CsvWriter` schreiben `=`, `+`, `-`, `@` am Feldanfang unverändert (Tabellenprogramme führen das aus); das Protokoll-CSV tut es korrekt (`AuditController.php:168`). Für Datenexporte ist das die richtige Wahl (Round-Trip, `RoundTripTest`) — in der Hilfe/Doku erwähnen.
* **I-04 · Erlaubte Hosts kennen keine Ports; Verbindungsaufbau ohne harte Zeitgrenze.** Mit erlaubtem `127.0.0.1` ist jeder lokale Port erreichbar, die Fehlermeldungen unterscheiden Dienste (Oracle für einen Port-Scan). Zeigt man den MySQL-Treiber auf einen PostgreSQL-Port, hängt der Aufbau 120 s (`PDO::ATTR_TIMEOUT` greift nicht; Probe `dsn-mysql2.php`) — eine Anfrage blockiert so einen FPM-Arbeiter. Erlaubte Hosts optional mit Port notieren (`host:port`) und den Aufbau zusätzlich hart begrenzen.
* **I-05 · `changes/preview` ohne Recht «schreiben».** `routes/web.php:76` → `RowChangeController::preview` prüft nur `view` (die Vorschau führt über `pretend()` nichts aus, liest aber Kaskaden-Zählungen). Konsistenz: `Gate::authorize('write')` auch hier.
* **I-06 · Hilfeseite mit `v-html` + AttributesExtension.** `HelpController.php:23` erzeugt HTML aus `resources/help/*.md` (`html_input => strip`, `allow_unsafe_links => false`) und `resources/js/pages/help/Show.vue:49` rendert es mit `v-html`. Heute mitgelieferter Text, also unkritisch; die `AttributesExtension` erlaubt aber Attribute (`{onclick=…}`) — wer die Datei pflegt, kann unabsichtlich XSS einbauen. Entweder Attribute auf eine Positivliste begrenzen oder den Text zu Komponenten rendern.
* **I-07 · Systemprüfung deckt zwei Konfigurationspunkte nicht ab.** `SystemCheck` prüft APP_KEY, APP_DEBUG in Produktion, HTTPS, Schreibrechte, Mail, Worker, Scheduler — nicht aber `PHPDBADMIN_ALLOWED_HOSTS` (Vorgabe `127.0.0.1,::1,localhost`; leer gesetzt heisst «nichts erlaubt», zu weit gesetzt heisst «alles erlaubt») und nicht `SESSION_SECURE_COOKIE`/`APP_URL`-Schema in Produktion. Je eine Zeile in `SystemCheck::run()`.

---

### 4. Was gut ist

* **Zugriffskontrolle.** `{connection}` wird vor der Routenbindung auf das Sichtbare eingeschränkt (404 statt 403), Policy `view ⊂ write ⊂ manage`, `share` nur für Administratoren. Fremde Aufträge, gespeicherte Abfragen, Verlauf, Rückgängig-Schnappschüsse und Passkeys sind je Konto geschlossen — jeder dieser Wege hat einen Test mit zweitem Konto. Admin-Routen hängen an `can:administrate`; die Policy verhindert, dass sich die letzte Administratorin selbst aussperrt.
* **Injection.** Bezeichner laufen durchgehend über die Grammatik bzw. `quoteIdentifier()` (mit dem bewussten Verzicht auf `wrap()` dort, wo dessen Zerlegen gefährlich wäre — gut begründet in `BaseDialect`), Werte immer gebunden. Rechte, Ebenen, Wartungsarten und Privilegien kommen aus festen Listen (`allowedPrivileges()` wirft, statt SQL zu bauen); Konto- und Hostnamen sind regex-geprüft und gequotet. `TrustedSql` macht jede bewusste Rohstelle auffindbar — genau das, was eine Prüfung braucht.
* **Zugangsdaten.** `encrypted`-Cast, `#[Hidden]`, Passwort nie als Prop (Test über die ausgelieferte Antwort), Sitzungspasswort verschlüsselt, Hintergrundaufträge tragen nur die Kennung, Passwort in Vorschau/Protokoll maskiert (`ServerPlan::mask`, `unsetEventDispatcher()` vor Anweisungen mit Passwort).
* **Schreibschutz in Schichten** mit echten Ausbruchsproben je Treiber (inkl. `commit`, `do $$…$$`, `nextval`, `pragma query_only`), Freigabe auf Zeit an die Sitzung gebunden, mit Pflichtgrund und im Protokoll; Produktionsbestätigung serverseitig über `Rule::in([$connection->name])`.
* **Maskierung und Protokoll.** Der Reporter in `bootstrap/app.php` ersetzt den Standard-Logeintrag für `QueryException` vollständig — Bindungen landen nicht im Log. Jede schreibende Aktion hat einen Eintrag, auch die gescheiterte, mit Zeilenzahl und ohne Werte; IP und fremde Adressen nur als HMAC; Aufbewahrung über `model:prune`.
* **Grenzen.** Zeitlimit je Anweisung, SQLite im Arbeitsprozess mit SIGKILL, Zeilengrenzen (ungepuffert + `KILL QUERY`, Server-Cursor), Export/Import in Paketen, Grenzen ohne Worker mit erklärender Meldung statt Timeout, Upload-Grösse und Endungen geprüft, Dateien unter `storage/app/private/transfers/<ULID>/source.<ext>` mit festem Namen (kein Pfaddurchstieg), Download nur über den Besitzer-Pfad.
* **Anmeldung.** Kein Passwort-Endpunkt (strenger als geplant: `Fortify::ignoreRoutes()`), gleiche Antwort für unbekannte Adressen und Mail erst nach der Antwort, Code als HMAC, einmalig per bedingtem Update (Rennen), Sitzung neu nach jeder Anmeldung, gesperrte Konten fliegen mit der nächsten Anfrage raus und auch über Passkey nicht herein, Ersteinrichtung über eine 0600-Datei auf dem Server.
* **Abhängigkeiten.** `composer audit`: keine Advisories; `npm audit --omit=dev`: 0 Lücken.

---

### 5. Proben (Wegwerf, `(Wegwerf-Probe, nicht im Repository)`)

| Datei | Zweck | Ergebnis |
|---|---|---|
| `dsn-injektion.php` | `host=`-Injektion über den Datenbanknamen, alle drei Serverdialekte | PostgreSQL voll verbunden zu `127.0.0.1` trotz Freigabe nur für `192.0.2.10` |
| `dsn-mysql.php`, `dsn-mysql2.php` | Varianten für MySQL/MariaDB, Gegenprobe mit totem Port | Host und Port überschrieben (`Connection refused` in 0,00 s statt 2 s Timeout), Datenbankname frei wählbar |
| `validierung.php` | gehen die Nutzlasten durch `TestConnectionRequest::rules()`? | ja, unverändert |
| `schreibschutz.php` | hält die Sitzungs-Schicht gegen `set session transaction read write`? | nein (MySQL und MariaDB), INSERT gelingt |

Ausgeführte Tests (unverändert, nur gelesen): `HostPolicyTest`, `StarterKitRemovalTest`, `DatabaseConnectionPolicyTest`, `MaskingTest` (66 Tests, grün) und `TargetConnectionsTest` (21 Tests, grün), je mit `PHPDBADMIN_TEST_DATABASE=phpdbadmin_test_pruefung`.

**Aufräumen:** Die Schreibschutz-Probe hat auf MariaDB und MySQL die Datenbank `phpdbadmin_n02_probe` angelegt; sie ist nach der Prüfung wieder entfernt (`aufraeumen.php`). **Im Repository wurde nichts geändert** — die Änderungen, die `git status` am Ende der Prüfung zeigt (KI-Hilfe im SQL-Editor, Sitzungsliste im Konto, `ai_api_key`), stammen aus der laufenden Arbeit des Hauptstrangs in derselben Arbeitskopie.

### 6. Nicht geprüft

* **Arbeit des Hauptstrangs, die während der Prüfung entstand** (unversioniert): `app/Http/Controllers/Workspace/SqlAssistController.php`, `app/Services/Ai/*`, `app/Http/Controllers/Account/{AiKeyController,SessionController}.php`, `app/Services/Auth/ActiveSessions.php`, Migration `…add_ai_api_key_to_users_table`. Erster Blick ohne Befund: Schlüssel mit `encrypted`-Cast und in `$hidden` (`app/Models/User.php:55,78`), `Gate::authorize('view', …)` und `Rule::in($this->catalog->schemas(…))` im Assistenten, nur Struktur im Umriss (`SchemaOutline`), Eintrag `sql.assist` im Protokoll. **Vor v1.0 eigens zu prüfen:** der ausgehende Aufruf zum KI-Anbieter liegt ausserhalb der erlaubten Hosts (eigene Regel nötig), der Schlüssel liegt in der App-Datenbank (siehe M-01), und es ist zu belegen, dass nie Zeilenwerte mitgehen (Q-08).
* **Laufzeitprüfung in der Oberfläche** (keine Browser-Werkzeuge erlaubt): CSP-Verstösse in der Konsole, Clickjacking-Probe, Verhalten der Drosselung im echten Anmeldefluss.
* **Ende-zu-Ende-Lauf von M-02** über eine echte Warteschlange (Mechanismus belegt, Zeitfenster aus dem Code abgeleitet).
* **Gesamter Testlauf** (`composer ci:local`): nur die fünf oben genannten Suiten wurden gefahren, um die Arbeitskopie des Hauptstrangs nicht zu blockieren.
