# phpdbadmin

Datenbanken im Browser verwalten — **MariaDB, MySQL, PostgreSQL und SQLite** in einer Oberfläche. Selbst gehostet, Anmeldung ohne Passwort (Code per Mail und Passkey), Deutsch und Englisch.

- **Ansehen:** Navigator über Datenbanken, Schemas und Objekte (Tabellen, Sichten, Routinen, Trigger, Sequenzen, Typen); Datenraster mit Filtern, Suche, Fremdschlüsseln in beide Richtungen und Zeilen-Detail; Struktur mit Indizes, Fremdschlüsseln, Triggern und DDL; Beziehungsdiagramm als SVG; Suche über ein ganzes Schema.
- **Ändern ohne Angst:** jede Änderung zuerst als SQL-Vorschau (mit den Zeilen, die `ON DELETE CASCADE` mitlöscht), dann in einer Transaktion angewendet und rückgängig zu machen — im Raster, in Ergebnissen des SQL-Editors und beim Suchen und Ersetzen. Verbindungen sind nach Umgebung markiert (lokal, Test, Produktion); Produktion verlangt den Namen der Verbindung, schreibgeschützte Verbindungen lassen sich mit Grund für 15 Minuten freigeben.
- **Struktur ändern:** Tabellen anlegen und ändern, Sichten und Routinen bearbeiten — je Treiber richtig angewendet (SQLite nach dem Umbauverfahren, PostgreSQL in einer Transaktion), mit Vorschau des genauen SQL.
- **SQL-Editor** mit Vervollständigung aus dem Katalog, Zeit- und Zeilengrenze, Abbrechen auf dem Server, EXPLAIN als Baum mit Hinweisen, Verlauf und gespeicherten Abfragen; Befehlspalette mit ⌘K; auf Wunsch SQL-Hilfe mit KI (nur mit eigenem Schlüssel, nur das Schema wird gesendet).
- **Export, Import, Sicherung:** CSV, JSON, SQL und Excel im Hintergrund; Sicherung und Wiederherstellung über `mysqldump`, `pg_dump` und `sqlite3`; Statistik nach Importen.
- **Vergleichen:** zwei Schemas gegenüberstellen — auch zwischen verschiedenen Datenbanken — mit Änderungsskript im Dialekt des Ziels; Laravel-Migration aus einer Tabelle.
- **Verbinden:** direkt oder über einen SSH-Tunnel; lokal mit Laravel Herd schlägt das Formular die Herd-Dienste und Sites vor.
- **Server-Verwaltung:** Prozesse, Variablen, Konten und Rechte, Wartung.
- **Mehrere Konten:** Rechte je Verbindung (lesen, schreiben, verwalten), Teilen, Einladungen, aktive Sitzungen und ein Protokoll jeder schreibenden Aktion — ohne Werte.

## Voraussetzungen

|               |                                                                                                                                                               |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP           | 8.5 mit `pdo`, `mbstring`, `openssl`, `ctype`, `fileinfo`, `tokenizer`; je nach Datenbank `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`; `zip` für Excel             |
| Composer      | 2                                                                                                                                                             |
| Node.js       | 22 oder neuer (nur zum Bauen der Oberfläche)                                                                                                                  |
| Webserver     | beliebig mit PHP-FPM, Dokumentenwurzel `public/` — oder Laravel Herd, Valet, `php artisan serve`                                                              |
| App-Datenbank | SQLite (Vorgabe), MariaDB, MySQL oder PostgreSQL — getrennt von den Datenbanken, die Sie verwalten                                                            |
| HTTPS         | für Passkeys nötig (ausser auf `localhost`)                                                                                                                   |
| Optional      | `ssh` für SSH-Tunnel; `mysqldump`/`mariadb-dump`, `pg_dump`/`pg_restore`/`psql`, `sqlite3` für Sicherung und Wiederherstellung (Pfade in `PHPDBADMIN_TOOL_*`) |

## Installation

```bash
git clone <Adresse des Repositorys> phpdbadmin
cd phpdbadmin
composer setup
```

`composer setup` installiert die Abhängigkeiten, legt `.env` an, baut die Oberfläche, richtet die App-Datenbank ein, prüft das System (`php artisan phpdbadmin:check`) und fragt nach Name und Mail-Adresse des ersten Kontos. Am Ende steht ein **Einrichtungslink** (30 Minuten gültig): Er meldet Sie an und legt gleich Ihren Passkey an.

Mit Composer geht es ebenso, sobald das Paket veröffentlicht ist: `composer create-project nguyen-development/phpdbadmin` und danach im neuen Ordner `composer setup`.

**Ohne Konsole auf dem Server** (Hosting mit Dateimanager): Code hochladen, Webserver auf `public/` zeigen lassen und die Seite öffnen. Die Ersteinrichtung im Browser verlangt einen Einrichtungsschlüssel, den phpdbadmin in `storage/app/private/einrichtungsschluessel.txt` ablegt — nur wer Zugriff auf die Dateien hat, kann das erste Konto anlegen.

### Danach einstellen (`.env`)

| Einstellung                             | Wozu                                                                                                       |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `APP_URL`                               | die Adresse, unter der phpdbadmin läuft (`https://…`)                                                      |
| `APP_ENV=production`, `APP_DEBUG=false` | für den Betrieb                                                                                            |
| `MAIL_MAILER=smtp`, `MAIL_*`            | Anmeldecodes und Einladungen; mit `log` stehen sie in `storage/logs/laravel.log`                           |
| `PHPDBADMIN_ALLOWED_HOSTS`              | zu welchen Datenbank-Servern phpdbadmin verbinden darf (Namen, Adressen, Netze) — schützt das interne Netz |
| `PHPDBADMIN_SQLITE_PATHS`               | Ordner, in denen SQLite-Dateien liegen dürfen (`~/…` erlaubt)                                              |

Weitere Grenzen (Zeit je Anweisung, Zeilen im Editor, Exporte, Rückgängig) stehen kommentiert in [`config/phpdbadmin.php`](config/phpdbadmin.php). Der Schlüssel `APP_KEY` verschlüsselt gespeicherte Passwörter der Verbindungen — **nie ändern**, sonst sind sie verloren.

### Für den Betrieb

```bash
# Scheduler (löscht abgelaufene Exporte und alte Protokolleinträge)
* * * * * cd /pfad/zu/phpdbadmin && php artisan schedule:run >> /dev/null 2>&1

# Worker für grosse Exporte und Importe im Hintergrund (als Dienst, z. B. systemd oder Supervisor)
php artisan queue:work --tries=1 --timeout=3600
```

Ohne Worker laufen Exporte und Importe in der Anfrage, bis zu einer Grenze (Vorgabe 100'000 Zeilen bzw. 20 MB). `php artisan phpdbadmin:check` zeigt jederzeit, was fehlt.

### Aktualisieren

```bash
git pull
composer upgrade
```

## Sicherheit

phpdbadmin speichert keine Passwörter von Konten. Zugangsdaten der Verbindungen liegen verschlüsselt in der App-Datenbank — oder gar nicht («nur für diese Sitzung»). Empfehlung: für jede Verbindung ein Datenbankkonto mit genau den nötigen Rechten; phpdbadmin warnt bei Superuser-Konten und Dateirechten. Das Bedrohungsmodell steht in [`dokumentationen/02-feasibility/sicherheit-und-bedrohungen.md`](dokumentationen/02-feasibility/sicherheit-und-bedrohungen.md).

## Entwicklung

```bash
composer install && npm ci
composer dev                # Server, Vite und weitere Entwicklungsprozesse
composer ci:local           # Prüfkette wie in der CI, dazu die App-Datenbank auf MariaDB, MySQL, PostgreSQL
```

Die Tests gegen echte Datenbanken erwarten MySQL (Port 3307), MariaDB (3306) und PostgreSQL (5432) mit `root` ohne Passwort, wie in Laravel Herd. Projekt-Dokumentation nach DSDM unter [`dokumentationen/`](dokumentationen/README.md).

## Lizenz

MIT — siehe [LICENSE](LICENSE). © Nguyen Development.
