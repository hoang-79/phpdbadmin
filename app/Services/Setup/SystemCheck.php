<?php

namespace App\Services\Setup;

use App\Connections\ConnectionNotAllowed;
use App\Connections\HostPolicy;
use App\Support\QueueHeartbeat;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Systemprüfung für Installation und Betrieb (B-05): Was phpdbadmin braucht, was nur einzelne Treiber oder Funktionen
 * brauchen und was für den Betrieb fehlt (Mail, Worker, Scheduler, HTTPS für Passkeys). Läuft im Befehl
 * `phpdbadmin:check` und vor `phpdbadmin:install`. Texte auf Deutsch wie die übrigen Konsolenbefehle.
 */
class SystemCheck
{
    public const string SCHEDULE_HEARTBEAT = 'phpdbadmin:schedule-heartbeat';

    /** Ohne diese Erweiterungen startet Laravel nicht oder phpdbadmin kann nicht verschlüsseln. */
    private const array REQUIRED_EXTENSIONS = ['pdo', 'mbstring', 'openssl', 'ctype', 'fileinfo', 'tokenizer'];

    /** Erweiterung → was ohne sie nicht geht. */
    private const array DRIVER_EXTENSIONS = [
        'pdo_mysql' => 'Verbindungen zu MySQL und MariaDB',
        'pdo_pgsql' => 'Verbindungen zu PostgreSQL',
        'pdo_sqlite' => 'SQLite-Dateien als Verbindung',
        'zip' => 'Export als Excel',
    ];

    public function __construct(
        private readonly Migrator $migrator,
        private readonly QueueHeartbeat $heartbeat,
    ) {}

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        return [
            $this->phpVersion(),
            ...$this->extensions(),
            $this->appKey(),
            $this->debugInProduction(),
            ...$this->appDatabase(),
            $this->frontendBuild(),
            $this->writableFolders(),
            $this->secureContext(),
            $this->secureCookie(),
            $this->appDatabaseExposure(),
            $this->mail(),
            $this->worker(),
            $this->scheduler(),
        ];
    }

    /**
     * @param  list<CheckResult>  $results
     */
    public static function passes(array $results): bool
    {
        return ! array_any($results, fn (CheckResult $result): bool => $result->level === CheckLevel::Fail);
    }

    private function phpVersion(): CheckResult
    {
        return version_compare(PHP_VERSION, '8.5.0', '>=')
            ? CheckResult::ok('PHP '.PHP_VERSION)
            : CheckResult::fail('PHP '.PHP_VERSION.' ist zu alt', 'phpdbadmin braucht PHP 8.5 oder neuer.');
    }

    /**
     * @return list<CheckResult>
     */
    private function extensions(): array
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn (string $extension): bool => ! extension_loaded($extension)));

        $results = [$missing === []
            ? CheckResult::ok('PHP-Erweiterungen: '.implode(', ', self::REQUIRED_EXTENSIONS))
            : CheckResult::fail('Es fehlen PHP-Erweiterungen: '.implode(', ', $missing), 'Erweiterungen installieren und PHP neu starten.')];

        $appDriver = (string) config('database.connections.'.config('database.default').'.driver');

        foreach (self::DRIVER_EXTENSIONS as $extension => $purpose) {
            if (extension_loaded($extension)) {
                continue;
            }

            $neededForAppDatabase = 'pdo_'.$appDriver === $extension || ($appDriver === 'mariadb' && $extension === 'pdo_mysql');

            $results[] = $neededForAppDatabase
                ? CheckResult::fail("PHP-Erweiterung {$extension} fehlt", 'Die App-Datenbank braucht sie. Erweiterung installieren und PHP neu starten.')
                : CheckResult::warn("PHP-Erweiterung {$extension} fehlt", "Ohne sie gehen keine {$purpose}.");
        }

        return $results;
    }

    private function appKey(): CheckResult
    {
        return filled(config('app.key'))
            ? CheckResult::ok('Schlüssel der App (APP_KEY) gesetzt')
            : CheckResult::fail('APP_KEY fehlt', 'php artisan key:generate — ohne Schlüssel lassen sich Zugangsdaten nicht verschlüsseln. Danach nie mehr ändern: gespeicherte Passwörter wären verloren.');
    }

    private function debugInProduction(): CheckResult
    {
        if (app()->isProduction() && config('app.debug')) {
            return CheckResult::fail('APP_DEBUG ist in Produktion eingeschaltet', 'APP_DEBUG=false setzen — sonst zeigen Fehlerseiten Pfade und Daten.');
        }

        return CheckResult::ok('Umgebung '.app()->environment().(config('app.debug') ? ', Fehlerdetails an' : ''));
    }

    /**
     * @return list<CheckResult>
     */
    private function appDatabase(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            return [CheckResult::fail('App-Datenbank nicht erreichbar', 'DB_* in .env prüfen ('.class_basename($exception).').')];
        }

        $repository = $this->migrator->getRepository();

        if (! $repository->repositoryExists()) {
            return [CheckResult::warn('App-Datenbank ist leer', 'php artisan migrate --force')];
        }

        $files = $this->migrator->getMigrationFiles(database_path('migrations'));
        $pending = array_diff(array_keys($files), $repository->getRan());

        return [$pending === []
            ? CheckResult::ok('App-Datenbank ('.DB::connection()->getDriverName().') auf dem neuesten Stand')
            : CheckResult::warn(count($pending).' Migration(en) ausstehend', 'php artisan migrate --force')];
    }

    private function frontendBuild(): CheckResult
    {
        return is_file(public_path('build/manifest.json'))
            ? CheckResult::ok('Oberfläche gebaut (public/build)')
            : CheckResult::fail('Oberfläche nicht gebaut', 'npm ci && npm run build (Node.js 22 oder neuer).');
    }

    private function writableFolders(): CheckResult
    {
        $notWritable = array_values(array_filter(
            [storage_path(), storage_path('app'), storage_path('logs'), base_path('bootstrap/cache')],
            fn (string $folder): bool => ! is_dir($folder) || ! is_writable($folder),
        ));

        return $notWritable === []
            ? CheckResult::ok('storage/ und bootstrap/cache/ beschreibbar')
            : CheckResult::fail('Nicht beschreibbar: '.implode(', ', $notWritable), 'Dem Benutzer des Webservers Schreibrechte geben.');
    }

    /** Passkeys verlangen einen sicheren Kontext: HTTPS oder localhost. */
    private function secureContext(): CheckResult
    {
        $url = (string) config('app.url');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($scheme === 'https' || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost')) {
            return CheckResult::ok('Adresse '.$url);
        }

        return CheckResult::warn('Adresse '.$url.' ohne HTTPS', 'Passkeys gehen nur über HTTPS (oder localhost). APP_URL auf https:// setzen und ein Zertifikat einrichten.');
    }

    private function secureCookie(): CheckResult
    {
        if (app()->isProduction() && str_starts_with((string) config('app.url'), 'https://') && ! config('session.secure')) {
            return CheckResult::warn('Sitzungs-Cookie auch über HTTP', 'SESSION_SECURE_COOKIE=true setzen — dann geht das Cookie nur über HTTPS.');
        }

        return CheckResult::ok('Sitzungs-Cookie '.(config('session.secure') ? 'nur über HTTPS' : 'Vorgabe'));
    }

    /**
     * Liegt die App-Datenbank auf einem Server, den Zielverbindungen erreichen dürfen (T-17)? Die Datenbank selbst ist
     * gesperrt — bei MySQL/MariaDB liest ein Konto mit Rechten aber auch über «datenbank.tabelle».
     */
    private function appDatabaseExposure(): CheckResult
    {
        $default = (string) config('database.default');
        $driver = (string) config("database.connections.{$default}.driver");

        if ($driver === 'sqlite') {
            return CheckResult::ok('App-Datenbank als Datei (SQLite), für Verbindungen gesperrt');
        }

        try {
            app(HostPolicy::class)->resolveHost((string) config("database.connections.{$default}.host"));
        } catch (ConnectionNotAllowed) {
            return CheckResult::ok('App-Datenbank auf einem Server ausserhalb der erlaubten Hosts');
        }

        return CheckResult::warn(
            'App-Datenbank auf einem erlaubten Server',
            'Verbindungen erreichen diesen Server. Die App-Datenbank ist gesperrt, aber ein Datenbankkonto mit Rechten auf sie könnte sie über vollständige Namen lesen. Eigenes Konto mit Passwort für die App verwenden, das niemand für Zielverbindungen kennt — oder SQLite.',
        );
    }

    private function mail(): CheckResult
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, ['log', 'array'], true)
            ? CheckResult::warn("Mail geht ins Log ({$mailer})", 'Anmeldecodes und Einladungen stehen dann in storage/logs/laravel.log. Für den Betrieb MAIL_MAILER=smtp und MAIL_* setzen.')
            : CheckResult::ok("Mail über {$mailer}");
    }

    private function worker(): CheckResult
    {
        if (config('queue.default') === 'sync') {
            return CheckResult::ok('Warteschlange synchron — Export und Import laufen in der Anfrage (mit Grenze)');
        }

        return $this->heartbeat->isAlive()
            ? CheckResult::ok('Worker der Warteschlange aktiv')
            : CheckResult::warn('Kein Worker der Warteschlange aktiv', 'Grosse Exporte und Importe laufen sonst in der Anfrage mit Grenze. Als Dienst starten: php artisan queue:work --tries=1 --timeout=3600');
    }

    private function scheduler(): CheckResult
    {
        $last = Cache::get(self::SCHEDULE_HEARTBEAT);

        return is_int($last) && Date::now()->getTimestamp() - $last <= 2 * 3600
            ? CheckResult::ok('Scheduler läuft')
            : CheckResult::warn('Scheduler läuft nicht (oder noch nie)', 'Cron-Eintrag anlegen: * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1 — er löscht abgelaufene Exporte und alte Protokolleinträge.');
    }
}
