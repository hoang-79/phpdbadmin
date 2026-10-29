# 05 — Deployment

> **PDF der Phase:** [deployment.pdf](deployment.pdf) — bei jeder Änderung neu gebaut (`python3 dokumentationen/werkzeuge/pdf-bauen.py --nur 05`).

**Stand 29.10.2026:** v1.0 ist installierbar (B-05, [TB 08](../04-evolutionary-development/timebox-08-sicherheit-v1.md)). Zielbild nach R-11 (lokal und selbst gehostet) und R-30:

| Stufe | Ort | Bemerkung |
|---|---|---|
| Dev | `https://phpdbadmin.test` | Laravel Herd (macOS), PHP 8.5; MySQL 8.4 :3307, MariaDB 10.11 :3306, PostgreSQL 18 :5432 |
| Demo | offen | öffentliche Demo mit SQLite-Beispieldatenbanken (cyon oder Laravel Cloud) — Entscheid Hoang |
| Produktion | beim Anwender | selbst gehostet neben den eigenen Datenbanken |

## Installation beim Anwender

Die Anleitung steht im [README des Repositorys](../../README.md). Kurz:

1. `git clone …` (später `composer create-project nguyen-development/phpdbadmin`)
2. `composer setup` — Abhängigkeiten, `.env`, Oberfläche bauen, App-Datenbank, Systemprüfung, erstes Konto mit Einrichtungslink
3. `.env`: `APP_URL` (HTTPS für Passkeys), `APP_ENV=production`, `APP_DEBUG=false`, `MAIL_*`, `PHPDBADMIN_ALLOWED_HOSTS`, `PHPDBADMIN_SQLITE_PATHS`, `SESSION_SECURE_COOKIE=true`
4. Cron `* * * * * php artisan schedule:run` (Aufräumen: Protokoll, Exporte, Codes, Einladungen) und ein Worker `php artisan queue:work --tries=1 --timeout=3600` als Dienst
5. `php artisan phpdbadmin:check` — zeigt, was fehlt

Ohne Konsole: Code hochladen, Dokumentenwurzel `public/`, Seite öffnen — die Ersteinrichtung verlangt den Einrichtungsschlüssel aus `storage/app/private/einrichtungsschluessel.txt` (T-19).

Aktualisieren: `git pull && composer upgrade` (Abhängigkeiten, Oberfläche, Migrationen, Systemprüfung). `APP_KEY` nie ändern — gespeicherte Passwörter der Verbindungen wären verloren.

## Betrieb und Sicherheit

- **Header:** CSP mit Nonce je Antwort, `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, HSTS in Produktion über HTTPS (`App\Http\Middleware\SecurityHeaders`). In `APP_ENV=local` meldet die CSP nur (Herd fügt ein eigenes Skript ein).
- **Erlaubte Hosts:** Zielverbindungen nur zu `PHPDBADMIN_ALLOWED_HOSTS`; Datenbanknamen, die den DSN verändern würden, und die App-Datenbank selbst sind nie erreichbar ([Sicherheitsbericht](../04-evolutionary-development/sicherheitspruefung-n02.md)).
- **App-Datenbank:** am besten SQLite oder ein eigener Server bzw. ein eigenes Konto mit Passwort, das niemand für Zielverbindungen kennt — `phpdbadmin:check` warnt sonst.
- **KI-Hilfe (Q-08):** aus, bis `PHPDBADMIN_AI=true`; jedes Konto braucht dann seinen eigenen Schlüssel; gesendet werden nur Frage und Schema an `api.anthropic.com`.

## Pipeline

Regel «GitHub Actions sparsam»: je Push ein schlanker Auftrag (Pint, Larastan, vue-tsc, `vp check`, Tests auf SQLite); Matrix MySQL/MariaDB/PostgreSQL wöchentlich und von Hand; `paths-ignore` für Doku, `concurrency` mit `cancel-in-progress`, `timeout-minutes`, Caches für Composer und npm. **Noch nie gelaufen** — es gibt noch kein GitHub-Repository (Hoang).
