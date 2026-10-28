# Timebox 00 — Fundament

**Stand:** 28.10.2026 · ✅ abgeschlossen — gestartet auf Hoangs «Ja, TB 00 starten», gebaut in fünf Scheiben nach dem [Timebox-Plan § 2](../03-foundation/timebox-plan.md#2-tb-00-in-scheiben); während Scheibe 5 auf Hoangs Wunsch ergänzt um **Ersteinrichtung und Passkey-Onboarding**.

## Ziel

Ein sauberes Fundament ohne Starter-Kit-Reste: Anmeldung ohne Passwort, Verbindungen zu vier Datenbanken anlegen und testen, eine Dialekt-Schicht mit Vertragstests — geprüft gegen MySQL 8.4, MariaDB 10.11, PostgreSQL 18 und SQLite.

## Umfang

| Scheibe | Anforderungen | Ergebnis |
|---|---|---|
| 1 Starter-Kit bereinigen | A-01, A-04, A-05, B-01 | ✅ |
| 2 Anmeldung | A-02, A-03, A-06, A-09 | ✅ (A-03: Handtest mit Touch ID offen, Hoang) |
| 3 Verbindungen | V-01, V-02, V-03, V-05, B-06 | ✅ |
| 4 Dialekt-Schicht | B-02 (+ Grundlage V-04, V-10) | ✅ |
| 5 Qualität und Abnahme | B-03, B-08 | ✅ lokal; Workflow noch nie auf GitHub gelaufen (kein Remote) |
| Zusatz: Ersteinrichtung | A-06, A-03 | ✅ (Wunsch Hoang während Scheibe 5) |

Wireframe-Bereiche 1 (Anmeldung), 2 (Code), 3 (Verbindungen), 4 (Verbindung anlegen), 13 (Konto).

## Was entstanden ist

**Anmeldung ohne Passwort (ADR-008, Nachtrag).** Fortify registriert keine eigenen Routen mehr (`Fortify::ignoreRoutes()`); `routes/auth.php` trägt nur die Passkey-Wege der Bibliothek. `POST /login` antwortet 405 — einen Passwort-Endpunkt gibt es nicht. Code per Mail: sechs Ziffern, HMAC-Prüfsummen für Code, Adresse und IP, zehn Minuten, einmalig (bedingtes Update gegen zwei gleichzeitige Einlösungen), Sperre nach fünf Fehlversuchen, benannte Limiter je Adresse und IP, gleiche Antwort für unbekannte Adressen, die Mail geht erst nach der Antwort hinaus (`defer`), die Sitzung wird neu erzeugt. Der Code steht bewusst nicht im Betreff (Sperrbildschirm). Die Passwortbestätigung ist ersetzt durch «Bestätigen» per Passkey oder Code an die eigene Adresse; jede Anmeldung gilt als Bestätigung. Passkeys anlegen, umbenennen, entfernen im Konto. Protokoll: Anmeldungen (mit Weg: Code, Passkey, Einladung, Einrichtung), Fehlversuche (Adresse nur als Prüfsumme), Abmelden, Passkey-Änderungen.

**Ersteinrichtung und Onboarding.** Ohne jedes Konto zeigt `/login` die Ersteinrichtung statt einer Anmeldung ohne Ausweg. `php artisan phpdbadmin:install` legt genau ein Administratorkonto an und gibt einen signierten Link aus (30 Minuten, gilt nur bis zur ersten Anmeldung; erst ein Knopf meldet an, damit Link-Vorschauen ihn nicht verbrauchen). Die erste Anmeldung gelingt so auch, wenn der Server noch keine Mail versenden kann; solange die Ersteinrichtung offen ist, stellt der Befehl einen neuen Link aus. Wer sich ohne Passkey anmeldet (Code, Einladung, Einrichtungslink), landet im Onboarding «Passkey jetzt anlegen»; das erste Konto sieht die Variante «Ersteinrichtung» mit den nächsten Schritten. A-06 bleibt: das erste Konto entsteht nur auf dem Server.

**Verbindungen.** Tabellen `connections` (ULID, Passwort mit `encrypted`-Cast) und `connection_user` nach dem Datenmodell. Anlegen, bearbeiten, entfernen für vier Treiber; Produktion schreibgeschützt vorgegeben; Passwort nie im Browser (die Seite erfährt nur, ob eines gespeichert ist), leeres Feld behält das gespeicherte, «nicht speichern» möglich. «Verbindung testen» mit Prüfschritten (Host erlaubt → Server erreichbar → Anmeldung → Rechte des Kontos), Fehler in Klartext je Treiber, Meldung des Servers zum Aufklappen. `HostPolicy`: Namen, Platzhalter, Adressen, Netze; einmal auflösen und zur geprüften Adresse verbinden; SQLite nur in erlaubten Ordnern über `realpath()`, die App-Datenbank nie. `{connection}` löst nur sichtbare Verbindungen auf — fremde sind 404 (T-16).

**Dialekt-Schicht (B-02).** Vertrag `App\Dialects\Dialect` mit Basis über Laravels Introspektion (Schema immer ausdrücklich) und vier Umsetzungen: Konfiguration (UTC, Zeitlimit beim Verbinden, SSL, Schreibschutz beim Öffnen bzw. für die Sitzung), Version, Fehlerzuordnung, Schemas, Tabellen und Sichten mit Zeilenschätzung, Check-Constraints und Trigger, Zeitlimit je Anweisung, Schreibschutz-Wächter (eine Anweisung je Aufruf in einer Nur-lesen-Transaktion, immer zurückgerollt), Kontorechte. `ConnectionFactory`: keine Mehrfachanweisungen, kein `LOCAL INFILE`, SQLite-`ATTACH` nur in erlaubten Ordnern (Authorizer).

**Gestaltung.** Palette aus `werkzeuge/kontraste.py` im Stylesheet (hell und dunkel), Inter und JetBrains Mono beim Build heruntergeladen und mit ausgeliefert (G-02), Wortmarke (G-06), Umgebungszeichen ○ ◐ ● mit Wort (G-03).

## Abnahmekriterien

| Anforderung | Kriterium (CR-001) | Nachweis |
|---|---|---|
| A-01 | alte Adressen 404/405, Spalten weg | `StarterKitRemovalTest`: 17 Adressen 404, `POST /login` 405, keine Spalten `password`, `two_factor_*`, keine Tabelle `password_reset_tokens` |
| A-02 | Erfolg, Ablauf, Wiederverwendung, falscher Code, Drosselung, unbekannte Adresse, Sitzung neu | `LoginCodesTest` (6), `LoginControllerTest` (14) — Sitzungs-ID vor/nach über das Cookie |
| A-03 | Zeremonie mit virtuellem Authenticator; einmal Touch ID | `PasskeyLoginControllerTest`, `PasskeyManagementTest`, `ConfirmControllerTest` mit dem Software-Authenticator (aus Basalto); **Touch ID von Hand: offen (Hoang)** |
| A-04 | `/dashboard` 404, Anmeldung führt zur Übersicht | `StarterKitRemovalTest`; Anmeldung mit Passkey → Übersicht, ohne Passkey → Onboarding |
| A-05 | `/settings/profile` 404, Kontomenü | Test + Browser-Durchlauf (Menü Sicherheit, Darstellung, Abmelden) |
| A-06 | Einladung annehmen, abgelaufene ablehnen; Befehl legt genau ein Admin-Konto an | `InvitationControllerTest` (7), `InstallCommandTest` (5), `InviteCommandTest` (3), `SetupControllerTest` (5) |
| A-09 | Eintrag mit Zeit, Konto bzw. Adresse (gehasht), IP-Prüfsumme | Tests prüfen `auth.login`, `auth.failed` (ohne Klartext-Adresse), `auth.logout` |
| V-01 | je Treiber ein Test gegen den echten Dienst; Fehler am Feld | `ConnectionTesterTest` (Herd-Dienste), `ConnectionControllerTest` |
| V-02 | je Fehlerart ein Test mit erzwungenem Fehler | nicht erreichbar, Zugang verweigert (ohne Passwort in der Meldung), Datenbank fehlt — je Server; SSL (PostgreSQL), Host nicht erlaubt, Datei keine Datenbank |
| V-03 | kein Passwort in Props und JSON; Spalte verschlüsselt | Übersicht, Bearbeiten, Test-Endpunkt; Rohwert ≠ Klartext |
| V-05 | nicht erlaubter Host und Pfad (auch `..`, Verknüpfung) | `HostPolicyTest` (21 Fälle aus 5 Tests), Controller am Feld; im Browser `~/Development/../../../etc/hosts` abgewiesen |
| B-01 | Routen und Seiten ohne Starter-Kit-Reste | Routenliste: nur eigene und Passkey-Wege; Seitenordner `auth`, `connections`, `account`, `onboarding`, `probe` |
| B-02 | Vertragstest gegen alle vier Umsetzungen | `DialectContractTest`: 12 Tests, 32 Fälle über die Treiber — alle grün |
| B-03 | lokales Skript fährt alle vier; Workflow sparsam | `composer ci:local`; Workflow mit `paths-ignore`, `concurrency`, `timeout-minutes`, Matrix wöchentlich — **noch nie auf GitHub gelaufen** |
| B-06 | Suite läuft mit jeder App-Datenbank | `composer test:app-db`: grün mit SQLite, MariaDB, MySQL, PostgreSQL als App-Datenbank |
| B-08 | Prüfkette in der Reihenfolge der CI | `vp check` → `vue-tsc` → Pint → Larastan (Stufe 7) → PHPUnit |

**Stand der Suite:** 211 Tests, 199 bestanden, 12 übersprungen (die schweren Raster- und Export-Proben mit 1 Mio. Zeilen, bewusst nur auf Wunsch).

## Abweichungen von den Wireframes

| Bereich | Abweichung | Grund |
|---|---|---|
| 01 Anmeldung | Sprachwahl Deutsch · English fehlt | Englisch ist nicht Teil von TB 00 |
| 01 Anmeldung | neu: Ersteinrichtung, wenn es kein Konto gibt | Wunsch Hoang; noch nicht im Generator |
| 02 Code | «Code abgelaufen» hat keine eigene Ansicht; eine Meldung für falsch und abgelaufen | verrät nichts über den Zustand eines Codes |
| 02 Code | Hinweis ergänzt: «…sofern es dazu ein Konto gibt» | ehrlich bei gleicher Antwort für unbekannte Adressen |
| 03 Verbindungen | ohne «Zuletzt geöffnet», Favoriten, Befehlspalette, Klick in den Arbeitsbildschirm | kommen mit ihren Timeboxes (V-06, TB 01, TB 11) |
| 04 Verbindung anlegen | SSL ohne «Zertifikat prüfen» | verbunden wird zur geprüften Adresse (V-05); Prüfen des Zertifikats braucht eine eigene Anforderung |
| 13 Konto | ohne Sitzungen und Dichte | A-08 (TB 04), G-04 mit dem Raster (TB 01) |
| 13 Konto | Kontomenü ohne «Verwaltung» | TB 07 |
| neu | Ersteinrichtung (Link) und Onboarding «Passkey jetzt anlegen» | Wunsch Hoang; Wireframes dazu noch nicht im Generator |

## Browser-Durchlauf

Gegen einen gebauten Stand in einem Herd-Workspace (der eingebaute Browser erreicht den Vite-Dev-Server nicht): Bereiche 1–4 und 13 bei 1440 und 375 px, hell und dunkel, Anmeldung per Code mit echter Mail in Herd, Verbindung zu MariaDB anlegen und testen, abgewiesener Pfad mit `..`, Ersteinrichtung auf einer Installation ohne Konto. Gefunden und behoben: Adressen brachen mitten im Wort, Codefeld zu klein, Hinweis ohne Mail-Symbol und Ablaufzeit, Kopf «Verbindung testen» brach um, **Warnsymbol fehlte wegen eines vergessenen Imports** (jetzt meldet `vue-tsc` unbekannte Komponenten), «· Konto» ohne Datenbank, SQLite-Platzhalter mit einem hier nicht erlaubten Ordner, leeres Passwort galt als «gespeichert».

## Entscheide in der Timebox

| Entscheid | Begründung |
|---|---|
| `Fortify::ignoreRoutes()` statt `authenticateUsing()` | kein Passwort-Endpunkt statt eines abweisenden (ADR-008, Nachtrag) |
| Route `login.code.show` statt `login.code.form` | Wayfinder hängt verschachtelte Routen an die Funktion; `form` überschrieb die Formular-Variante und machte das Formular zum GET |
| kein «angemeldet bleiben» (`remember_token` entfernt) | Datenmodell § 1; Sitzungsdauer genügt |
| Probe-Seiten bleiben lokal hinter der Anmeldung | Sichtprüfung des Editors durch Hoang offen; Raster (TB 01) und SQL-Editor (TB 05) ersetzen sie |
| Proben F-1, F-7 und Teile von F-2 → Vertragstests | Probe-Dateien entfernt; Abbrechen, DDL-Vorschau, Raster und Export bleiben Proben bis zu ihren Timeboxes |
| Lesekonto `phpdbadmin_lesen` und Datenbanken `phpdbadmin_test`, `phpdbadmin_app_test` auf den lokalen Herd-Servern | Vertragstest «warnt nicht» (V-10) und Suite mit App-Datenbank auf den Servern |

## Commits

`083e3c9` Scheiben 1+2 · `b04de27` Scheibe 3 · `ceab0e8` Scheibe 4 · `2bea65c` Scheibe 5 (Prüfskript, Workflow, Funde) · `95aa78c` Ersteinrichtung und Onboarding · Abschluss mit diesem Protokoll.

## Offen

- **Touch ID von Hand** (A-03): einmal einen Passkey auf dem Mac anlegen und damit anmelden — Hoang.
- **Workflow auf GitHub:** noch kein Remote; der erste Push zeigt, ob beide Aufträge laufen.
- **Sicherheitshinweis `symfony/mime`** (CVE-2026-45070, mittel, am 28.10.2026 veröffentlicht): Update der Abhängigkeit braucht Hoangs Freigabe.
- Wireframes für Ersteinrichtung und Onboarding im Generator nachziehen.
- Sichtprüfung der Gestaltung durch Hoang am eigenen Bildschirm (G-01 bis G-08).

## Gelernt

- Textersetzungen per Skript immer mit Prüfung, ob die Stelle gefunden wurde — der Formatierer bricht Zeilen um, und eine stille Nicht-Ersetzung liess ein Symbol verschwinden (Lektion im Vault).
- `vue-tsc` meldet unbekannte Komponenten erst mit `checkUnknownComponents` (Lektion im Vault).
- Wayfinder: Routennamen nie auf `.form` enden lassen (Lektion im Vault).
- Der eingebaute Browser erreicht den Vite-Dev-Server nicht; für Sichtprüfungen einen Herd-Workspace mit Build nutzen und dessen `artisan dev` anhalten (Projekt-Memory).
