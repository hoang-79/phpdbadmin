# Timebox 14 — Aktualisieren über die Oberfläche

**Stand:** 13.11.2026 · ✅ gebaut — Wunsch von Hoang: «für die aktualisierung, möchte ich, dass es über die web-oberfläche updaten kann. wenn ein neuer update verfügbar ist, dann kann man über den button die app updaten.»

## Ziel

Wer phpdbadmin mit dem Web-Installer (oder als hochgeladenes Archiv) eingerichtet hat, hat keine Konsole — bis v1.4.0 gab es für diese Installationen keinen Weg zur nächsten Version. Jetzt sehen Administratoren eine neue Version und aktualisieren mit einem Knopf; dabei darf nichts schlechter werden als vorher: kein fremder Code, keine halbe Installation, keine verlorenen Einstellungen.

## Umfang

| Anforderung | Plan | Ergebnis |
|---|---|---|
| B-09 Aktualisieren über die Oberfläche: Hinweis auf neue Versionen, Knopf, Signaturprüfung, Wartungsmodus, Rückkehr bei Fehlern | Must | ✅ |

Neu im [CR-001](../00-change_request/CR-001-phpdbadmin.md) (Fassung 7); im Bedrohungsmodell [T-22 und T-23](../02-feasibility/sicherheit-und-bedrohungen.md).

## Was entstanden ist

**Neue Version sehen.** `UpdateChannel` holt `latest.json` des neuesten Releases bei GitHub (oder unter `PHPDBADMIN_UPDATE_URL`) und nimmt es nur mit gültiger Ed25519-Signatur an — derselbe öffentliche Schlüssel wie im Web-Installer, jetzt auch in `phpdbadmin.release.public_key`. Der Stand liegt 12 Stunden im Zwischenspeicher (ein Fehler eine Stunde). Fehlt er, fragt phpdbadmin *nach* der Antwort an ein Administratorkonto nach (`app()->terminating`) — die Seite wartet nie auf GitHub, und für andere Konten fragt es gar nicht. `PHPDBADMIN_UPDATE_CHECK=false` schaltet das Nachsehen ab; «Jetzt nachsehen» bleibt. Hinweis: Punkt am Kontomenü, Eintrag «Version … verfügbar», Punkt am neuen Reiter **Verwaltung → Aktualisierung**.

**Aktualisieren in vier Schritten** (`AppUpdater`), je eine eigene Anfrage — so stösst keiner an die Zeitgrenze des Hosters, und der letzte läuft schon im neuen Code:

1. *Herunterladen und Signatur prüfen:* Manifest neu holen, nur eine neuere Version, PHP und Erweiterungen des Release vergleichen, Platz prüfen, Archiv laden, Grösse und SHA-256 gegen das signierte Manifest.
2. *Entpacken und prüfen:* jeder Pfad geprüft (genau ein oberster Ordner, kein `..`, keine Verknüpfungen), Pflichtdateien vorhanden, die Version in `config/phpdbadmin.php` des Archivs ist die des Manifests (gelesen, nicht ausgeführt), PHP-Untergrenze aus `composer.json` des Archivs.
3. *Programm austauschen:* Wartungsmodus an (vorab gerenderte Seite «phpdbadmin wird aktualisiert», `Retry-After`, `Refresh`), das Konto, das aktualisiert, kommt mit dem Laravel-Cookie `laravel_maintenance` durch (HMAC über ein Geheimnis je Aktualisierung, darum von der Cookie-Verschlüsselung ausgenommen). Dann jeder oberste Eintrag des Archivs per Umbenennen an seinen Platz, die bisherige Fassung in `storage/app/private/aktualisierung/sicherung-<version>`; aus `database/` und `public/` jeder Eintrag einzeln (dort liegen die SQLite-Datei und Eigenes), `storage/` gar nicht (nur fehlende Ordner kommen dazu), `.env` nie. Liegt der Webordner getrennt vom Programm (Web-Installer), kommen die Einträge aus `public/` als Kopie dorthin — nie `index.php`, `.htaccess` oder ein Eintrag mit Punkt am Anfang (also nie `.phpdbadmin`). Danach OPcache leeren, wenn erlaubt.
4. *App-Datenbank anpassen und abschliessen:* im Code der neuen Version — läuft noch der alte (OPcache), antwortet der Server «noch einmal» (409) und der Browser fragt bis zu zehnmal nach. Migrationen, Zwischenspeicher wie vorher (`config:cache` usw. nur, wo sie es schon waren), Wartungsmodus aus, Protokoll `app.updated`.

**Scheitern.** Scheitert der Tausch, gehen alle Einträge zurück und der Wartungsmodus aus. Scheitert eine Migration, rollt phpdbadmin den Stapel dieser Aktualisierung über den Migrator zurück, holt die bisherige Fassung zurück, schaltet den Wartungsmodus aus und protokolliert `app.update_failed`; vom Versuch bleibt nur der Zustand. Die Seite nennt den Grund und dass die bisherige Version weiterläuft. Schliesst das Fenster nach dem Tausch, holt der Scheduler den Abschluss nach zwei Minuten nach (`phpdbadmin:update --finish`, auch im Wartungsmodus); wer den Durchlass-Cookie hat, kann auf der Seite «Jetzt abschliessen».

**Was verhindert, und warum.** Git-Installationen (`.git` im Programmordner) bekommen keinen Knopf, sondern `git pull` und `composer upgrade` — das schützt auch jede Entwicklungskopie. Ebenso: fehlende Schreibrechte (mit den Ordnern), fehlendes `zip` oder `sodium`, OPcache ohne Neulesen und ohne Recht zum Leeren, ein Release, das ein neueres PHP oder fehlende Erweiterungen verlangt.

**Vertrag zwischen den Versionen.** Den letzten Schritt führt die neue Version aus. Jede Version behält deshalb `POST admin/update/finish`, die Antwort `{ok, version}` bzw. `{message, retry}` und den Zustand in `storage/app/private/aktualisierung/zustand.json` (`step`, `from`, `to`, `batch`, `cached`, `journal`).

**Release.** `phpdbadmin:release` baut nicht mehr, wenn der Geheimschlüssel nicht zu `phpdbadmin.release.public_key` passt — sonst entstünde ein Release, das keine Installation annimmt.

**Konsole.** `phpdbadmin:update` sieht nach und nennt, was dagegen spricht; `--finish` schliesst einen liegengebliebenen Austausch ab. Austauschen selbst geht nur über die Oberfläche: Nur dort kennt phpdbadmin den Webordner des Web-Installers (`public_path()` setzt dessen `index.php`).

## Abnahmekriterien

| Kriterium | Nachweis |
|---|---|
| Tausch behält `.env`, App-Datenbank, `storage/`; Sicherung bleibt | `AppUpdaterTest::test_replaces_the_program_and_keeps_settings_app_database_and_storage` |
| Webordner des Web-Installers: neuer Build, `index.php`/`.htaccess`/Eigenes bleiben — neben und mit dem Programm darin | `…::test_web_folder_of_the_web_installer_gets_the_new_build_and_keeps_its_own_files` (beide Anordnungen) |
| Manipuliertes Archiv, Pfad ausserhalb, falsche Version, gleiche oder ältere Version, Git, PHP und Erweiterungen | `…::test_refuses_an_archive_that_differs_from_the_signed_manifest`, `…_with_a_path_outside_its_folder`, `…_that_carries_another_version_than_its_manifest`, `…::test_never_installs_the_same_or_an_older_version`, `…::test_git_checkout_and_requirements_of_the_release_block_the_update` |
| Fremde Signatur, geänderte Version, Quelle nicht erreichbar, Zwischenspeicher | `UpdateChannelTest` (8 Tests) |
| Gescheiterte Migration: Stapel zurück (auch mit `prohibitDestructiveCommands` wie in Produktion), alte Version zurück, Wartungsmodus aus | `AppUpdaterTest::test_a_failed_migration_rolls_back_its_batch_and_brings_back_the_previous_version` |
| Gescheiterter Tausch: jeder Eintrag zurück | `…::test_a_failed_replacement_puts_every_entry_back` |
| Nur Administratoren; Hinweis nur für sie; vier Schritte über HTTP durch den Wartungsmodus; «noch alter Code» = 409 | `UpdateControllerTest` (9 Tests), `UpdateCommandTest` (3 Tests) |
| Durchlauf mit echten Archiven | 1.4.90 mit dem echten Web-Installer installiert (Programm ausserhalb des Webordners), im Browser auf 1.4.91 aktualisiert: Version, neue Migration als eigener Stapel, Build im Webordner, `index.php` und `.htaccess` unverändert, `.env` unverändert, Protokoll. Dann 1.4.92 mit absichtlich scheiternder Migration: 1.4.91 läuft wieder, die gelungene Migration ist zurückgerollt, nichts liegt herum. Eine fremde Anfrage während des Tauschs bekam die Wartungsseite (503). |

## Gelernt

- **Produktion sperrt `migrate:rollback`.** `DB::prohibitDestructiveCommands(app()->isProduction())` im AppServiceProvider sperrt den Befehl still (Rückgabe «Fehler», keine Ausnahme) — die Tests liefen in `testing` und waren grün, erst der Durchlauf mit dem echten Archiv zeigte die stehengebliebene Migration. Zurückgerollt wird jetzt über den Migrator; der Test setzt die Sperre wie in Produktion.
- **`set_time_limit()` auf der Konsole schafft eine Grenze, wo keine war.** Der erste Lauf der Prüfkette brach nach 300 Sekunden ab: Mein `set_time_limit(600)` im Testprozess machte `max_execution_time` > 0, und `TransferRunner` setzte danach 300. Jetzt wie dort: nur verlängern, wo es eine Grenze gibt.
- **Laravels Testclient schickt Cookies bei JSON-Anfragen nur mit `withCredentials()`** — der Durchlass durch den Wartungsmodus schien im Test ungültig, war es aber nicht.
