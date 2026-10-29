# TB 07 — Konten, Rechte je Verbindung, Teilen, Protokoll

**Stand:** 29.10.2026 · ✅ gebaut auf einem eigenen Zweig (Basis `80fed1d`), noch nicht zusammengeführt — vorgezogen vor TB 04, parallel zu Raster und Export. Dazu V-06 (Favoriten, «zuletzt geöffnet») aus TB 01.

## Ziel

Mehrere Personen arbeiten sicher auf einer Installation: Rechte je Verbindung, Teilen durch die Verwaltung, Konten verwalten im Browser und ein Protokoll, in dem nie ein Wert aus einer Zieldatenbank steht.

## Umfang

| Anforderung | Inhalt | Ergebnis |
|---|---|---|
| **B-04** (Must) | Protokoll mit Verbindung, Ziel, SQL, Zeilen; Maskierung; Frist | ✅ Dienst und Ansicht; schreibende Aktionen auf Zielverbindungen gibt es erst ab TB 04 — sie rufen `AuditLog::record()` mit `connection`, `target`, `sql`, `bindings`, `affectedRows`, `error` |
| A-07 | Rollen und Rechte je Verbindung | ✅ `DatabaseConnectionPolicy` (`view`, `write`, `manage`, `share`) |
| V-09 | persönlich oder geteilt | ✅ Dialog «Teilen …» und Rechte je Konto in der Verwaltung |
| A-05, A-06 | Name und Adresse durch die Verwaltung; Einladung im Browser | ✅ |
| V-06 | Favoriten, sortieren, «zuletzt geöffnet» | ✅ (Suche und Gruppen gab es schon) |

Wireframe-Bereiche 14 (Verwaltung: Konten, Einladungen, Protokoll) und 03 (Verbindungen: Favoriten, Sortierung).

## Was entstanden ist

**Rechte (A-07).** Die Policy beantwortet vier Fähigkeiten; die Rechte bauen aufeinander auf (lesen ⊂ schreiben ⊂ verwalten):

| Fähigkeit | erlaubt | wer |
|---|---|---|
| `view` | Daten und Struktur ansehen, SQL lesend, exportieren | jedes Recht |
| `write` | Daten und Struktur ändern, importieren, Schreibschutz auf Zeit aufheben (V-11) | «schreiben» und «verwalten» |
| `manage` | Verbindung bearbeiten, mit gespeichertem Passwort testen, entfernen | «verwalten» |
| `share` | Teilen und Rechte vergeben | nur Administratoren |

Besitzer haben an ihren Verbindungen «verwalten», Administratoren an allen **geteilten**. Persönliche Verbindungen anderer sieht niemand, auch kein Administrator — sie bleiben 404 (Routenbindung, T-16); sichtbare ohne Recht antworten 403. `DatabaseConnection::permissionFor()` ist die eine Regel, `scopeVisibleTo` und `isManageableBy` folgen ihr.

**Teilen (V-09).** In der Übersicht öffnet «Teilen …» (nur Administratoren) einen Dialog: Teilen ein/aus und je Konto «kein Zugriff · lesen · schreiben · verwalten». Ausschalten entzieht allen ausser dem Besitzer den Zugriff. Besitzer und Administratoren bekommen kein gespeichertes Recht. Karten zeigen «geteilt» und das eigene Recht.

**Verwaltung (Wireframe 14).** `/admin/accounts`, `/admin/invitations`, `/admin/audit`, nur Administratoren (Gate `administrate`, sonst 403), Einstieg im Kontomenü. Konten mit Rolle, letzter Anmeldung, Passkey; je Konto Name und Adresse ändern (A-05), Rolle, Sperren, Entfernen und «Rechte je Verbindung». `UserPolicy`: Rolle, Sperre und Entfernen nie am eigenen und nie am letzten aktiven Administratorkonto. **Gesperrt** (neue Spalte `users.disabled_at`): kein Code, kein Passkey-Zutritt, laufende Sitzungen enden mit der nächsten Anfrage (`EnsureAccountIsActive`), Sitzungen in der Datenbank werden gelöscht. **Entfernen:** Passkeys, persönliche Verbindungen und Rechte gehen mit; geteilte Verbindungen des Kontos übernimmt der Administrator. **Rolle verloren:** wer nicht mehr Administrator ist, startet ohne Rechte an fremden Verbindungen. **Einladungen** im Browser mit Ablauf, erneut senden, zurückziehen; nach dem Senden erscheint der Link einmal (für Installationen ohne Mail). `phpdbadmin:invite` nutzt denselben `InvitationSender`.

**Protokoll (B-04).** `audit_log` hat neu `connection_id` (ohne Fremdschlüssel — ein Eintrag überlebt seine Verbindung) sowie `user_name` und `connection_name` (lesbar nach dem Entfernen, Datenmodell § 4). Ansicht mit Filtern nach Konto, Verbindung, Art und Zeitraum, Suche in Anweisung und Ziel, Seiten zu 50, Export als CSV (Zellen mit `= + - @` entschärft; der Export steht selbst im Protokoll).

**Maskierung (T-11).** `App\Services\Audit\Masking`:

- `Masking::sql($sql, $bindings, $doubleQuotedStrings)` — Zeichenketten- und Zahl-Literale werden zu `?`; Bezeichner (`` `…` ``, `"…"`), Platzhalter (`?`, `$1`, `:name`) und Kommentare bleiben; `"…"` gilt bei MySQL und MariaDB als Zeichenkette.
- `Masking::message($message, $bindings)` — eingesetzte Bindungen (an Wortgrenzen, «1» trifft nicht «1062»), `'…'`, «near '…' at line», PostgreSQL «Key (…)=(…)», «Failing row contains (…)», «invalid input syntax … "…"».
- `Masking::exception($throwable)` — Meldung des Servers plus Anweisung mit Platzhaltern.

`AuditLog` speichert Anweisung und Fehler nur maskiert; Datenbankfehler gehen maskiert ins Log (Reporter in `bootstrap/app.php`, ersetzt den üblichen Eintrag); Zielverbindungen setzen `mask_bindings_in_exception_messages`.

**Aufbewahrung.** `phpdbadmin.audit.retention_days` (`PHPDBADMIN_AUDIT_RETENTION_DAYS`, Vorgabe 365, 0 = ohne Frist; Datenmodell § 5 entschieden: Konfiguration, angezeigt in der Verwaltung), `AuditEntry` ist `Prunable`, `model:prune` täglich im Scheduler (braucht den Cron-Eintrag für `schedule:run`).

**Favoriten und «zuletzt geöffnet» (V-06).** Stern je Karte, Filter «Favoriten», Favoriten oben; Sortierung umschaltbar (nach Gruppe dann zuletzt geöffnet · nach Name · zuletzt geöffnet zuerst), im Browser gemerkt. `WorkspaceController::show` setzt `last_opened_at`. Die Merker liegen in `connection_user`; Besitzer und Administratoren bekommen dafür eine Zeile mit ihrem wirksamen Recht «verwalten» — eine Merker-Zeile gibt so nie mehr Recht, als das Konto ohnehin hat.

## Abnahmekriterien

| Anforderung | Kriterium (CR-001) | Nachweis |
|---|---|---|
| A-07 | Policy-Tests je Recht; fremde Verbindung 404 statt 403 | `DatabaseConnectionPolicyTest` (10 Fälle: Rolle × Besitz × Teilen × Recht, dazu Sichtbarkeit = Policy), `ConnectionControllerTest` (403 für «lesen» auch vor der Validierung, 200 für «verwalten», Administrator 404 an persönlicher Verbindung) |
| V-09 | persönliche Verbindung ist für andere 404 | `SharingControllerTest` (9): Teilen, «kein Zugriff», Teilen beenden → 404 für Mitglied und Administrator, Besitzer weiter 200; nur Administratoren (403), Validierung |
| B-04 | jede schreibende Aktion genau ein Eintrag; Bereinigung nach Frist; ein gebundener Wert erscheint in keiner Meldung | `AuditLogTest` (7): echte Fehler auf **MySQL, MariaDB, PostgreSQL, SQLite** (doppelter Schlüssel, falscher Zahlwert) — der Wert steht in keinem Feld des Eintrags; maskiertes Log; Frist 30 Tage und 0; `model:prune` täglich. `MaskingTest` (5 Tests, 14 Fälle). «Genau ein Eintrag je schreibender Aktion» prüfen die Aktionen ab TB 04 |
| A-05 | Name und E-Mail nur durch die Verwaltung | `AccountControllerTest` (13), `UserPolicyTest` (6 Fälle) |
| A-06 | Einladung mit Ablauf | `Admin\InvitationControllerTest` (6), Rückzug macht den Link ungültig |
| V-06 | Durchlauf | `FavoriteControllerTest` (6), `WorkspaceLastOpenedTest` (2); Browser-Durchlauf der Übersicht steht aus (siehe unten) |

Gesperrte Konten: `LoginControllerTest`, `PasskeyLoginControllerTest`, `EnsureAccountIsActiveTest`.

## Abweichungen von den Wireframes

| Bereich | Abweichung | Grund |
|---|---|---|
| 14 Legende «verwalten» | «zusätzlich Verbindung bearbeiten und entfernen» statt «… und mit anderen teilen» | Teilen ist nach V-09 und CR Sache der Administratoren |
| 14 Konten | offene Einladungen stehen im Reiter «Einladungen», nicht zusätzlich in der Kontenliste; «Erneut senden» und «Zurückziehen» dort | eine Liste je Sache; der Reiter zählt die offenen |
| 14 Konten | Rechte-Tabelle zeigt geteilte Verbindungen und die eigenen persönlichen des Administrators, keine persönlichen des gewählten Kontos | persönliche Verbindungen anderer sieht niemand (V-09) |
| 14 Konten | «Entfernen» verlangt den Namen zur Bestätigung | G-08: zerstörende Handlung |
| 14 Protokoll | «ältere laden» als Seiten (neuere · ältere) | einfache Seiten mit Filtern in der Adresse |
| 14 Protokoll | Ergebnis «abgelehnt» für Fehlversuche, «Fehler» bei gescheiterten Aktionen; «schreibgeschützt» erst mit TB 04 | es gibt noch keine schreibenden Aktionen |
| 03 Verbindungen | Seitenleiste «Zuletzt geöffnet» (Tabellen, Abfragen) fehlt; die Karten zeigen «zuletzt geöffnet» je Verbindung | Verlauf je Objekt kommt mit Q-04 (TB 03) |
| 03 Verbindungen | Favoriten oben in jedem Abschnitt, nicht in einem eigenen Abschnitt | wie im Wireframe (Stern an der Karte); Filter «Favoriten» zeigt nur sie |

Die Wireframes 03 und 14 sind im Generator noch nicht nachgezogen.

## Tests

Neu: 11 Testdateien mit 60 Tests (davon zwei Matrizen mit 16 Fällen); erweitert: `ConnectionControllerTest`, `LoginControllerTest`, `PasskeyLoginControllerTest`. Die Maskierung ist gegen echte Fehlermeldungen aller vier Treiber geprüft. Die neuen Tests laufen grün mit SQLite, MariaDB, MySQL und PostgreSQL als App-Datenbank; die Migrationen laufen auf allen vier. Zweites Konto für Rechte: in jedem Endpunkt-Test (Mitglied 403, fremde Verbindung 404, Gast zur Anmeldung).

Gesamtlauf zuletzt: 476 Tests, 464 bestanden, 12 übersprungen (die schweren Proben).

## Entscheide in der Timebox

| Entscheid | Begründung |
|---|---|
| Administratoren sehen alle geteilten, aber keine persönlichen Verbindungen anderer | «Administrator darf alles» ohne Einblick in persönliche Zugänge; Datenmodell § 2.2 |
| Merker-Zeilen für Besitzer und Administratoren mit Recht «verwalten» statt `permission` leer erlauben | keine Spaltenänderung (der Migrations-Wächter lehnt `change()` ab); die Zeile gibt nie mehr Recht als die Rolle; beim Rollenverlust werden die Zeilen an fremden Verbindungen gelöscht |
| `connection_id` im Protokoll ohne Fremdschlüssel, mit Namen von Konto und Verbindung | Einträge überleben Konto und Verbindung (Datenmodell § 4) |
| Neue Migrationen ohne `down()` | Protokoll- und Kontodaten sollen beim Zurückrollen nicht still verschwinden; der Migrations-Wächter weist `dropColumn` ab |
| Einladungslink einmal auf der Seite | wie der Befehl; der Link steht nur bis zur nächsten Anfrage in der Sitzung |
| Datenbankfehler maskiert ins Log statt des üblichen Eintrags | Datenmodell § 1.9 und Solution-Architektur § 13: keine Werte aus Zieldatenbanken im Log |

## Commits

`4322f65` fehlende englische Zeilen der Basis · `f720d71` Scheibe 1 Policy · `863b47b` Scheibe 2 Teilen · `3359df0` Scheibe 3 Verwaltung · `6d2f991` Scheibe 4 Protokoll · `f7eac95` Scheibe 5 Favoriten · Doku.

## Offen

- **Browser-Durchlauf** nach dem Zusammenführen (Hauptstrang): Übersicht mit Favoriten und Sortierung, Protokoll mit echten Einträgen, bei 1440 und 375 px, hell und dunkel. Teilen, Konten und Einladungen sind vor dem Zusammenführen im Workspace `tb07-rechte` durchgespielt.
- **Fehlermeldungen im Raster** (TB 02): `TableData` sollte Meldungen der Server mit `Masking::exception()` zeigen; Zielverbindungen maskieren schon die eingesetzten Bindungen, nicht aber die Werte in den Meldungen der Server.
- **Schreibende Aktionen** (TB 04 ff.) protokollieren mit `AuditLog::record(..., connection:, target:, sql:, bindings:, affectedRows:, error:)` und prüfen mit `write`/`manage` der Policy.
- Fristen für Anmeldecodes und Einladungen (`model:prune`, Datenmodell § 4) — der tägliche Lauf steht, die Modelle sind noch nicht `Prunable`.
- Wireframes 03 und 14 im Generator nachziehen.

## Gelernt

- Der Migrations-Wächter (Plugin) weist `dropColumn` und `change()` ab — Schemaänderungen additiv planen (Spalte dazu, keine Typänderung).
- Merker und Rechte in derselben Tabelle: Eine Merker-Zeile darf nie ein Recht geben, das die Rolle nicht schon gibt — sonst bleibt beim Rollenwechsel still ein Zugriff zurück.
- `mask_bindings_in_exception_messages` maskiert nur Laravels eingesetzte Anweisung; MySQL und PostgreSQL zitieren Werte auch in ihrer eigenen Meldung («Duplicate entry '…'», «Key (…)=(…)»).
