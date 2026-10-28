# Ausgangslage und Rückfragen — phpdbadmin

**Stand:** 28.10.2026, erste Runde mit interaktiven Antworten zu R-01, R-03, R-04, R-11 und R-40 (Abschnitt 4). Dieses Dokument sammelt, was feststeht, was ich selbst nachgelesen habe und was nur Hoang beantworten kann. Jede Rückfrage trägt eine **Annahme**, die gilt, solange nichts dagegen spricht. Die wichtigsten Fragen werden interaktiv gestellt (Optionen zum Anklicken, Empfehlung zuerst); beantwortete Fragen bekommen das Datum der Antwort. Aus den Antworten entsteht die nächste Fassung von [CR-001](../00-change_request/CR-001-phpdbadmin.md).

## 1. Was feststeht

| Thema | Festlegung | Quelle |
|---|---|---|
| Vorhaben | Admin-Werkzeug für **MariaDB, MySQL, PostgreSQL und SQLite** wie phpMyAdmin oder phpPgAdmin, aber **intuitiver und benutzerfreundlicher**, für alle vier Plattformen | Hoang 28.10. |
| Rahmen | **Laravel-Projekt** im Repo `~/Development/phpdbadmin`, Herd-Site `phpdbadmin.test` | Hoang 28.10. |
| Starter-Kit | **Passwort entfernen** — nur Passkey und Code per Mail («Magic Code») · **Dashboard entfernen** · **Profil entfernen** | Hoang 28.10. |
| Dokumentation | in `dokumentationen/` nach DSDM wie Fristo und Basalto; Beginn mit dem Pre-Project | Hoang 28.10. |
| Sprache | Deutsch mit echten Umlauten in Doku, Kommentaren und Commits | globale Regeln |
| Arbeitsweise | Rückfragen interaktiv mit Empfehlung zuerst; Defaults ohne Rückfrage, nachfragen nur bei hohem Blast-Radius | globale Regeln, Lektionen |
| GitHub Actions | sparsam: ein schlanker Auftrag je Push, Schweres wöchentlich oder von Hand, Doku ohne Lauf | globale Regeln (25.10.2026) |
| Träger und Lizenz | **Produkt von Nguyen Development, Open Source unter MIT** | R-01, R-40 |
| Betriebsmodell | **lokal (Herd) und selbst gehostet** auf eigenen Servern, mehrere Konten mit Rechten; **kein SaaS** | R-11 |
| Vorrang | **neben Basalto** in kleinen Timeboxes; Basalto behält Vorrang bis zur Beta; Stufen 0–1 zuerst | R-03 |
| Name | **phpdbadmin**, endgültig | R-04 |

## 2. Was ich selbst nachgelesen habe (keine Rückfrage nötig)

| Frage | Antwort | Quelle |
|---|---|---|
| Was bringt das Starter-Kit mit? | Routen `home`, `dashboard` (auth + verified), `settings/profile`, `settings/security` (mit `RequirePassword`), `settings/password`, `settings/appearance`, `.well-known/passkey-endpoints`; Seiten `Welcome`, `Dashboard`, `auth/*` (Login, Register, ForgotPassword, ResetPassword, ConfirmPassword, TwoFactorChallenge, VerifyEmail), `settings/*` (Profile, Security, Appearance); Fortify mit Registrierung, Passwort-Reset, E-Mail-Bestätigung, Zwei-Faktor und **Passkeys** (`confirmPassword: true`) — gleiche Lage wie Basalto vor TB 00 | `routes/web.php`, `routes/settings.php`, `resources/js/pages`, `config/fortify.php` |
| Welche Versionen sind installiert? | Laravel 13.39.0 · Fortify 1.41.0 (bringt `laravel/passkeys` mit) · Inertia Laravel 3.5.2 · Wayfinder 0.1.23 · PHPUnit 12.5.42 · Larastan 3.13.2 · Pint 1.32.1 · Boost 2.10.3; npm: `@inertiajs/vue3` 3, `@laravel/passkeys` 0.2, Vue 3.5, Tailwind 4, `vite-plus` 1.4.0 | `composer show --direct`, `package.json` |
| Welche Datenbanken laufen lokal? | Herd: **MariaDB 10.11.6 :3306**, **PostgreSQL 18 :5432**, SQLite nativ; MySQL war nicht installiert — seit 28.10.2026 **MySQL 8.4.11 :3307** (R-33, Freigabe Hoang) | Herd `services list` 28.10.2026 |
| Wie ist die Site erreichbar? | `https://phpdbadmin.test` (Herd: gesichert, PHP 8.5, Node 24); `APP_URL` in der `.env` steht noch auf `http://localhost:8000`, `APP_NAME=Laravel`, `APP_LOCALE=en`, App-Datenbank SQLite | Herd `site info`, `.env` (nur Schlüsselnamen gelesen) |
| Was kann Laravel 13 schon für mehrere Datenbanken? | Verbindungen zur Laufzeit: `DB::build()`, `DB::connectUsing()`; Introspektion für alle Treiber: `Schema::getSchemas()`, `getTables()`, `getViews()`, `getTypes()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`; Vorschau: `Blueprint::toSql()`, `Connection::pretend()` | Quellcode `vendor/laravel/framework` 13.39 |
| Was hat Basalto, das hier wiederverwendbar ist? | Starter-Kit-Bereinigung mit Passkey- und Code-Anmeldung (TB 00, Scheibe 1), Tests auf drei bzw. vier Datenbanken, Diagramm-Generator und PDF-Bau der Doku, Lektionen zu Fortify und `laravel/passkeys` (Routen-Übernahme bei Minor-Update, globale Bindung von `{passkey}`) — eigenes Projekt, Übernahme erlaubt | `~/Development/basalto`, Vault [[Nguyen Development]] |
| Wie steht der Markt? | phpMyAdmin 5.2.3 nur bis PHP 8.3 freigegeben, phpPgAdmin ruht, Adminer 6.2.0 aktiv, Jaxon DbAdmin 0.35 als neuer PHP-Mitbewerber | [Marktbild](../00-change_request/marktbild.md) |
| Ist der Name frei? | `phpdbadmin.ch` frei, `phpdbadmin.com` belegt seit 30.08.2025; keine gleichnamige Software gefunden | Whois und Websuche 28.10.2026 |

## 3. Rückfragen

### A. Rahmen und Governance

| Nr. | Frage | Annahme (gilt, solange nichts dagegen spricht) | Antwort/Entscheid |
|---|---|---|---|
| R-01 | **Wer trägt das Vorhaben?** Eigenständiges Produkt der Einzelfirma Nguyen Development (wie Basalto), privates Werkzeug (wie Fristo als Nebenprodukt) oder ein Modul von Basalto? | Eigenständiges Produkt von Nguyen Development, zuerst für den eigenen Gebrauch | ✅ 28.10.2026: **wie Annahme** — Produkt von Nguyen Development (zusammen mit R-40: MIT) |
| R-02 | **Rollen:** alle DSDM-Rollen in Personalunion? Gibt es einen Gegenleser (Business Ambassador)? | Personalunion; Hoang ist erster Nutzer; ein Laravel-Entwickler aus dem Umfeld als Gegenleser ab Alpha | |
| R-03 | **Vorrang und Zeit** gegenüber Basalto (Beta Anfang 2027) und Fristo (Pilot Frühling 2027) | Basalto behält Vorrang bis zur Beta; phpdbadmin läuft in kleinen Timeboxes daneben, Stufen 0–1 zuerst | ✅ 28.10.2026: **wie Annahme** — neben Basalto |
| R-04 | **Name:** Arbeitstitel «phpdbadmin» behalten oder Namensfindung (wie bei Basalto)? Der Name ist nahe an phpMyAdmin und nennt Technik statt Nutzen | Arbeitstitel bis vor dem ersten öffentlichen Auftritt; Namensfindung als Prüfpunkt der Feasibility (F-10) | ✅ 28.10.2026: **phpdbadmin ist der Name** (endgültig) — F-10 wird «Name absichern»: Markenrecherche, Domain `.ch`, eigenständiger Auftritt |
| R-05 | **Arbeitsvertrag:** Gilt die Meldung der Nebenbeschäftigung wie bei Basalto auch hier? | Ja, Vorbedingung vor dem ersten öffentlichen Auftritt | |
| R-06 | **Doku-Spiegel:** nur Repo und Vault? | Nur Repo und Vault-Projektnotiz | |
| R-07 | **Repo:** GitHub `hoang-79/phpdbadmin`, privat? | Privat bis v1.0, dann nach Lizenzentscheid (R-40) | |
| R-08 | **Branches und Auslieferung:** direkt auf `main`, gebündelte Pushes? | `main` lokal, Pushes gebündelt; Auslieferungsweg mit dem Betriebsmodell (R-11) | |

### B. Produkt und Positionierung

| Nr. | Frage | Annahme | Antwort/Entscheid |
|---|---|---|---|
| R-10 | **Zielgruppe zuerst:** Laravel-/PHP-Entwickler lokal, Betriebsteams auf Servern oder Fachleute mit Datenpflege? | Entwickler zuerst, Betriebsteams gleich danach (Rechte, Protokoll in v1.0); Fachleute profitieren von der Führung | |
| R-11 | **Betriebsmodell:** (a) nur lokal (Herd, ein Benutzer) · (b) lokal und selbst gehostet auf einem Server (mehrere Konten) · (c) zusätzlich als SaaS | (b) — kein SaaS, weil dafür fremde Zugangsdaten und Netzwege auf unseren Servern lägen | ✅ 28.10.2026: **wie Annahme** — lokal und selbst gehostet |
| R-12 | **Mehrere Konten ab v1.0?** | Ja: Einladung, Rollen, Rechte je Verbindung (A-06, A-07) | |
| R-13 | **Was heisst «intuitiver» konkret** — gibt es Vorbilder, die dir gefallen (TablePlus, Beekeeper Studio, Tabellen-Editor von Supabase, Notion-Datenbanken)? | Arbeitsfläche wie TablePlus, Bearbeiten wie der Tabellen-Editor von Supabase, festgehalten in den Prinzipien P-01 bis P-11 und im [Bedienkonzept](../00-change_request/diagrams/bedienkonzept.svg) | |
| R-14 | **Weitere Datenbanken später** (SQL Server, Oracle, CockroachDB, ClickHouse, MongoDB, Redis)? | Nicht vor v1.2; die Dialekt-Schicht hält die Tür offen (SQL Server hat einen Laravel-Treiber) | |
| R-15 | **Mindestversionen der Server** | MariaDB 10.6, MySQL 8.0, PostgreSQL 14, SQLite 3.35 — in der Feasibility prüfen | |
| R-16 | **phpMyAdmin-Gewohnheiten** übernehmen (Reiter «Operationen», URL-Aufbau, Designer)? | Nein, eigene Struktur; Begriffe dort angelehnt, wo sie verbreitet sind | |
| R-17 | Soll phpdbadmin seine **eigene App-Datenbank** als Verbindung zeigen dürfen? | Nein — sonst liessen sich Konten und Rechte am System vorbei ändern | |

### C. Funktionsumfang

| Nr. | Frage | Annahme | Antwort/Entscheid |
|---|---|---|---|
| R-20 | **Umfang v1.0** = Stufen 0–2 wie im [Scope-Diagramm](../00-change_request/diagrams/scope-abgrenzung.svg)? | Ja | |
| R-21 | Verwaltung der **Datenbank-Benutzer und Rechte** (GRANT) erst in v1.1? | Ja | |
| R-22 | **Export-Formate** in v1.0: CSV, JSON, SQL; Excel später? | Ja | |
| R-23 | **KI-Hilfe** für SQL: nur optional, eigener Schlüssel, nur das Schema wird gesendet, Stufe 4? | Ja | |
| R-24 | **Laravel-Extras** (Migration aus Tabelle, Herd-Erkennung) erst in Stufe 4 oder früher als Unterscheidungsmerkmal? | Stufe 4 | |
| R-25 | Darf das **Datenraster waagrecht scrollen** (Ausnahme von «keine Scrollbalken»)? | Ja, nur dort: fixierte erste Spalte, Verlaufskante mit Chevron, Spaltenwahl | |
| R-26 | **Rückgängig für Datenänderungen** (D-09) schon in v1.0? | Ja als Should, begrenzt auf eine Zeilenzahl je Änderung | |

### D. Technik und Betrieb

| Nr. | Frage | Annahme | Antwort/Entscheid |
|---|---|---|---|
| R-30 | **Umgebungen** ausser Dev: Testing, Demo, Produktion? | Bis v1.0 nur Dev; danach eine öffentliche Demo mit SQLite-Beispieldatenbanken (Ort offen: cyon wie Basalto oder Laravel Cloud) | |
| R-31 | **App-Datenbank** | SQLite als Vorgabe, die anderen drei möglich (B-06) | |
| R-32 | **Neue Pakete** für SQL-Editor (CodeMirror 6, MIT) und Raster-Virtualisierung (TanStack, MIT) | Probe in der Feasibility (`composer`/`npm` trocken), Entscheid per ADR, Freigabe durch Hoang | ✅ 28.10.2026: probeweise installiert (Freigabe Hoang); Entscheid in ADR-005/006 |
| R-33 | **MySQL in Herd** installieren (8.4, Port 3307) für die Testmatrix | Ja, in TB 00 | ✅ 28.10.2026: in der Feasibility installiert (Herd meldete einen Fehler, legte den Dienst aber an) |
| R-34 | **Mail:** lokal Herd-Mail, sonst SMTP der Installation | Ja | |
| R-35 | **Bezeichner** im Code und in der App-Datenbank | Englisch (wie Fristo und Basalto), Oberfläche Deutsch und Englisch | |
| R-36 | **Zeitzone** | Speichern in UTC, Anzeige in Europe/Zurich bzw. der Zeitzone des Kontos | |

### E. Geschäft

| Nr. | Frage | Annahme | Antwort/Entscheid |
|---|---|---|---|
| R-40 | **Lizenz:** Open Source (MIT), Open Core oder kommerziell? | Open Source (MIT); Team-Funktionen später allenfalls als bezahlter Zusatz | ✅ 28.10.2026: **wie Annahme** — MIT |
| R-41 | **Vertrieb:** Git und `composer create-project`; Docker-Image? | Git und Composer ab v1.0, Docker-Image als Could | |
| R-42 | **Bezug zu Basalto:** eigenständig oder später ein Basalto-Modul «Datenbank»? | Eigenständig; gemeinsamer Code nur über Pakete | |

## 4. Entscheide aus der interaktiven Runde vom 28.10.2026

| Nr. | Gefragt | Gewählt | Folge |
|---|---|---|---|
| R-01, R-40 | Träger und Lizenz | Nguyen Development, MIT (Empfehlung) | Lizenzdatei MIT vor dem ersten öffentlichen Push; Abhängigkeiten MIT-verträglich (F-9); kein GPL-Code |
| R-11 | Betriebsmodell | lokal und selbst gehostet (Empfehlung) | Konten, Einladung, Rechte je Verbindung ab v1.0; Hosts-Liste; Installationsweg für Server (B-05) |
| R-03 | Vorrang | neben Basalto (Empfehlung) | kleine Timeboxes, Stufen 0–1 bis zur Alpha zuerst |
| R-04 | Name | **phpdbadmin ist der Name** (statt Arbeitstitel mit späterer Namensfindung) | `phpdbadmin.ch` sichern (Hoang); Markenrecherche zur Nähe zu «phpMyAdmin» und eigenständiger Auftritt vor dem ersten öffentlichen Auftritt (F-10, Risiko R6) |

Neue Rückfragen ergeben sich daraus keine; die übrigen Fragen laufen mit ihrer Annahme weiter.

## 5. Entscheide aus dem Review der Foundation vom 28.10.2026

| Nr. | Gefragt | Gewählt | Folge |
|---|---|---|---|
| R-27 | Schreibschutz einer Produktions-Verbindung auf Zeit aufheben (Wireframe E-05) | ja, als neue Anforderung (Empfehlung) | **V-11** in CR-001 Fassung 4, TB 04 |
| R-28 | Raster: endlos scrollen oder Seiten zu 50 (Wireframe zeigte Seiten) | endlos scrollen (Empfehlung) | ADR-006 bestätigt; Wireframe-Fusszeile auf Positionsanzeige umgestellt |
| R-29 | PHP 8.5 als Mindestversion (ADR-011) | PHP 8.5 (Empfehlung) | ADR-011 angenommen; `composer.json` `^8.5` in TB 00 |
| R-30 | Übrige Wireframe-Fragen F-2 bis F-6 (Anwenden-Knopf rot, Detail-Schublade breit, neue Zeilen oben, Name am Fremdschlüssel, Startseite) | wie empfohlen | in der Wireframe-README als entschieden geführt |

