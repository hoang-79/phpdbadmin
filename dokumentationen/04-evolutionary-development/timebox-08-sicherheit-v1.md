# Timebox 08 — Sicherheit, Barrierefreiheit, Mobil, Hilfe, Installation → v1.0

**Stand:** 29.10.2026 · ✅ abgeschlossen bis auf den axe-Lauf (siehe O-08) — auf Hoangs Auftrag «mache alle TB durch» ohne Rückfragen.

## Ziel

phpdbadmin so weit bringen, dass jemand es installieren und sicher betreiben kann: geprüft gegen das Bedrohungsmodell, bedienbar mit Tastatur und auf dem Telefon, mit kurzer Hilfe und einer Installation in einem Befehl.

## Umfang

| Anforderung | Plan | Ergebnis |
|---|---|---|
| N-02 Sicherheit | **Must** | ✅ Prüfbericht nach OWASP, ein hoher Befund gefunden und behoben ([Bericht](sicherheitspruefung-n02.md)) |
| O-07 Tablet und Telefon | Should | ✅ Durchlauf bei 375, 768 und 1440 px, ein Befund behoben |
| O-08 WCAG 2.2 AA | Should | ◐ Ersatzprüfung ohne axe (siehe unten) |
| N-05 kurze Hilfe | Should | ✅ Seite «Hilfe», Verweise aus der Oberfläche, Test über alle Anker |
| B-05 Installation | Should | ✅ `composer setup` in einem leeren Ordner: 11 s mit warmen Caches, Anmeldung per Code geprüft |

## Was entstanden ist

**Sicherheit (N-02).** Ein eigener, nur lesender Prüfdurchgang über Code, Tests und Proben gegen die lokalen Server. Er fand eine DSN-Injektion über den Datenbanknamen: `postgres' host='10.0.0.5` verband an den erlaubten Hosts vorbei — jetzt am Feld und bei jedem Verbinden abgewiesen. Dazu: App-Datenbank auch auf einem Server nie als Ziel, Aufträge prüfen Recht und Schreibschutz beim Ausführen erneut, Drosselung der Code-Prüfung je Adresse, CSP mit Nonce und Sicherheits-Header, keine `storage`-Route, Einladungen melden nie in bestehende Konten an, CSRF-Test mit echter Middleware, Passwörter im Verlauf maskiert, Leser nur mit lesender Sitzung. Alles im [Bericht](sicherheitspruefung-n02.md) mit Test je Befund.

**Mobil (O-07).** Durchlauf bei 375 px (Verbindungen, Raster als Karten, Struktur, SQL-Editor, Server, Verwaltung, Export), 768 px (Raster, Export) und 1440 px. Befund: bei 768 px quetschte das Suchfeld im Kopf den Verbindungs-Chip auf einen Buchstaben — der Chip schrumpft nicht mehr, das Suchfeld schon. Bearbeiten bleibt auf dem Telefon möglich, ist aber für den Desktop gebaut (O-07: «Bearbeiten am Desktop»).

**Barrierefreiheit (O-08).** Ersatzprüfung, weil axe hier nicht laufen kann (siehe unten): Kontraste aller Farbpaare in Hell und Dunkel über `werkzeuge/kontraste.py` (alle ≥ 4,5:1, Fokus ≥ 3:1); jeder Knopf und Link ohne sichtbaren Text hat einen Namen (Skript über alle Vue-Dateien, zwei Fundstellen geprüft: beide über Inhalt bzw. `<label>` benannt); Bedienbaumstruktur im Browser gelesen; neu: Sprunglink «Zum Inhalt springen» als erster Tab-Halt, `main` als Ziel; `lang` am Dokument je Konto. **Offen:** axe selbst — er braucht ein Skript im Browser, und jeder Skriptaufruf löst bei Hoang einen Bestätigungsdialog aus. Vorschlag: einmal von Hand mit der axe-Erweiterung über Anmeldung, Verbindungen, Raster, Zeilen-Detail, Vorschau, SQL-Editor, Export.

**Hilfe (N-05).** Seite `help` aus `resources/help/de.md` und `en.md` (Markdown, HTML darin wird entfernt), elf Abschnitte mit festen Ankern. Einstiege: Kontomenü, Befehlspalette, Kürzel-Dialog; Verweise im Freigabe-Dialog, in der SQL-Vorschau, in der Server-Verwaltung und in der Verwaltung. «Doku-Prüfung im Commit»: `HelpControllerTest` prüft, dass jeder `<HelpLink anchor>` in beiden Sprachen einen Abschnitt trifft und beide Sprachen dieselben Abschnitte haben.

**Installation (B-05).** `composer setup` (Abhängigkeiten, `.env`, Oberfläche bauen, `phpdbadmin:install`) und `composer upgrade` nach `git pull`. `phpdbadmin:install` erzeugt einen fehlenden `APP_KEY` (einen vorhandenen nie), migriert, zeigt die Systemprüfung und legt das erste Konto mit Einrichtungslink an; ohne Terminal nennt es die fehlenden Optionen. `phpdbadmin:check` meldet Hindernisse (PHP, Erweiterungen, Schlüssel, Debug in Produktion, App-Datenbank, Oberfläche, Schreibrechte) und Betriebslücken (Mail im Log, kein Worker, kein Scheduler, HTTPS für Passkeys, Cookie, App-Datenbank auf erlaubtem Server). README mit Voraussetzungen, Installation, Betrieb (Cron, Worker), Aktualisieren; LICENSE (MIT). Durchlauf: `git clone` in einen leeren Ordner, `composer setup`, Konto angelegt, mit `php artisan serve` angemeldet (Code aus dem Log), Ersteinrichtung bis zur Verbindungsübersicht — **11 Sekunden** mit warmen Caches für Composer und npm; ohne Caches schätzungsweise 2–5 Minuten (Download), weit unter 15.

**Nebenbei behoben.**
- Laravels Vorgabe `QUEUE_CONNECTION=database` ohne laufenden Worker liess jeden Export für immer «wartet» stehen — der Worker meldet sich jetzt mit einem Lebenszeichen (TB 06, `QueueHeartbeat`).
- `trans_choice()` fiel für deutsche Schlüssel auf Englisch zurück (Laravel wählt die Mehrzahlregel der Sprache, in der es den Schlüssel findet; `de.json` gibt es nicht) — `SourceLocaleTranslator` behandelt Deutsch als Quellsprache.

## Abnahme v1.0 (Timebox-Plan § 3)

| Kriterium | Stand |
|---|---|
| Kernabläufe (Bearbeiten, Struktur, Export, Import) auf vier Treibern getestet, inklusive Elterntabelle mit Kindern | ✅ TB 04 (`RowEditorTest`), TB 05 (Elterntabelle mit Kindern je Treiber), TB 06 (Rundlauf je Treiber) |
| White-Box-Sicherheitsprüfung nach OWASP ohne hohen Befund | ✅ nach Behebung von H-01 (`cab3ec5`) |
| axe ohne Befund in den Kernbildschirmen | ◐ Ersatzprüfung; axe-Lauf offen (Hoang, von Hand) |
| Installation in einem leeren Ordner unter 15 Minuten | ✅ 11 s mit Caches |

## Tests

Neu: `CheckCommandTest` (4), `InstallCommandTest` (+2), `HelpControllerTest` (4), `SecurityHeadersTest` (2), `RequestForgeryTest` (3), `SourceLocaleTranslatorTest` (2), dazu Fälle in `HostPolicyTest` (+9), `TestConnectionControllerTest` (+2), `TransferRunnerTest` (+3), `LoginControllerTest` (+1), `InvitationControllerTest` (+1), `QueryRunnerTest` (+1), `MaskingTest` (+5), `SqlControllerTest` (+1). Suite: 996 Tests, 976 bestanden, 20 übersprungen (schwere Proben).

## Commits

`b43bf31` Lebenszeichen des Workers · `6ed5766`, `52d6802` Installation und Systemprüfung · `fbfcbd8` Hilfe · `58f2f72` Sprunglink, Kopf auf dem Tablet · `cab3ec5` Befunde der Sicherheitsprüfung.

## Gelernt

- Ein Schutzmuster, das nicht kompiliert, lässt alles durch: `preg_match` gibt `false`, und `!== 1` liest das als «kein Treffer». Prüfungen fail-closed schreiben (`=== 0`) — im Vault: `regex-pruefung-scheitert-offen`.
- Konfiguration ist kein Lebenszeichen: Eine Warteschlange, die konfiguriert ist, hat noch keinen Worker — im Vault: `warteschlange-ohne-worker-wartet-ewig`.
- Herd fügt lokalen Seiten ein Inline-Skript ohne Nonce hinzu; eine strikte CSP sperrt es. Lokal deshalb `Report-Only`, überall sonst durchgesetzt.
