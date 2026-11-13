<?php

namespace Tests\Concerns;

use App\Services\Audit\AuditLog;
use App\Services\Release\ReleaseSignature;
use App\Services\Update\AppUpdater;
use App\Services\Update\UpdateChannel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Eine Installation aus dem Release-Archiv (Version 1.4.0) in einem eigenen Ordner und ein signiertes neueres Release
 * hinter einer vorgetäuschten Quelle — die Aktualisierung tauscht echte Dateien, nie die des Repositorys. Der
 * Speicherordner der App zeigt in die Installation: Wartungsmodus und Zustand landen dort.
 */
trait UpdateInstallation
{
    protected string $installRoot;

    protected string $core;

    protected string $webFolder;

    private string $releaseSecretKey;

    /**
     * @param  'none'|'outside'|'inside'  $webFolder  Webordner des Web-Installers: keiner (public/ des Programms),
     *                                                neben dem Programm oder mit dem Programm darin (.phpdbadmin)
     */
    protected function setUpInstallation(string $webFolder = 'none'): void
    {
        $this->installRoot = sys_get_temp_dir().'/phpdbadmin-update-test-'.bin2hex(random_bytes(5));
        [$this->core, $this->webFolder] = match ($webFolder) {
            'outside' => [$this->installRoot.'/phpdbadmin-app', $this->installRoot.'/public_html'],
            'inside' => [$this->installRoot.'/public_html/.phpdbadmin', $this->installRoot.'/public_html'],
            default => [$this->installRoot.'/phpdbadmin', $this->installRoot.'/phpdbadmin/public'],
        };

        $this->writeFiles($this->core, $this->releaseFiles('1.4.0', ['app/Alt.php' => '<?php // nur in 1.4.0']));
        $this->writeFiles($this->core, [
            '.env' => "APP_KEY=base64:geheim\n",
            'database/database.sqlite' => 'App-Datenbank',
            'storage/app/private/eigenes.txt' => 'Daten der Installation',
            'storage/logs/laravel.log' => 'Protokoll',
            'bootstrap/cache/packages.php' => '<?php // Zwischenstand von 1.4.0',
        ]);

        if ($webFolder !== 'none') {
            $this->writeFiles($this->webFolder, [
                'index.php' => '<?php // phpdbadmin-installer:index',
                '.htaccess' => '# BEGIN phpdbadmin-guard',
                'build/manifest.json' => '{"version":"1.4.0"}',
                'build/assets/app-1.4.0.js' => 'console.log("1.4.0")',
                'favicon.svg' => '<svg>1.4.0</svg>',
                'eigenes.html' => 'vom Betreiber',
            ]);
        }

        $this->app->useStoragePath($this->core.'/storage');

        $pair = sodium_crypto_sign_keypair();
        $this->releaseSecretKey = sodium_crypto_sign_secretkey($pair);
        config([
            'phpdbadmin.version' => '1.4.0',
            'phpdbadmin.release.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'phpdbadmin.update.manifest_url' => 'https://releases.example.test/latest.json',
        ]);

        $core = $this->core;
        $public = $this->webFolder;
        $this->app->bind(AppUpdater::class, fn ($app): AppUpdater => new AppUpdater($app->make(UpdateChannel::class), $app->make(AuditLog::class), $core, $public));

        Http::preventStrayRequests();
    }

    protected function tearDownInstallation(): void
    {
        if (isset($this->installRoot) && is_dir($this->installRoot)) {
            exec('chmod -R u+w '.escapeshellarg($this->installRoot).' && rm -rf '.escapeshellarg($this->installRoot));
        }
    }

    /**
     * Was ein Release dieser Version mitbringt — genug für alle Prüfungen der Aktualisierung.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    protected function releaseFiles(string $version, array $extra = []): array
    {
        return [
            'artisan' => '#!/usr/bin/env php',
            'composer.json' => (string) json_encode(['require' => ['php' => '^8.5']]),
            'composer.lock' => '{}',
            'README.md' => '# phpdbadmin '.$version,
            'app/Models/Beispiel.php' => '<?php // '.$version,
            'bootstrap/app.php' => '<?php // '.$version,
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
            'config/phpdbadmin.php' => "<?php\n\nreturn [\n    'version' => '".$version."',\n];\n",
            'database/.gitignore' => "*.sqlite*\n",
            'database/migrations/0001_01_01_000000_create_users_table.php' => '<?php // '.$version,
            'lang/en.json' => '{}',
            'public/index.php' => '<?php // public/index.php '.$version,
            'public/.htaccess' => 'RewriteEngine On',
            'public/favicon.svg' => '<svg>'.$version.'</svg>',
            'public/build/manifest.json' => '{"version":"'.$version.'"}',
            'public/build/assets/app-'.$version.'.js' => 'console.log("'.$version.'")',
            'resources/views/app.blade.php' => '<html>'.$version.'</html>',
            'routes/web.php' => '<?php // '.$version,
            'storage/app/.gitignore' => "*\n!.gitignore\n",
            'storage/framework/views/.gitignore' => "*\n!.gitignore\n",
            'vendor/autoload.php' => '<?php // vendor '.$version,
            ...$extra,
        ];
    }

    /**
     * Signiertes Release unter https://releases.example.test/: `latest.json` und das Archiv.
     *
     * @param  array<string, string>  $extra  zusätzliche oder geänderte Dateien des Release
     * @param  array<string, mixed>  $manifest  Felder des Manifests überschreiben (nach dem Signieren)
     * @param  array<string, string>|null  $entries  Einträge des Archivs genau so (statt der Dateien des Release)
     * @param  bool  $tamper  ein Byte des Archivs nach dem Signieren ändern
     */
    protected function publishRelease(string $version = '1.5.0', array $extra = [], array $manifest = [], ?array $entries = null, bool $tamper = false): void
    {
        $file = 'phpdbadmin-'.$version.'.zip';
        $zipPath = $this->installRoot.'/quelle/'.$file;
        File::ensureDirectoryExists(dirname($zipPath));

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries ?? $this->releaseFiles($version, $extra) as $name => $content) {
            $zip->addFromString($entries === null ? 'phpdbadmin-'.$version.'/'.$name : $name, $content);
        }

        $zip->close();

        $body = (string) file_get_contents($zipPath);
        $size = strlen($body);
        $sha256 = hash('sha256', $body);

        if ($tamper) {
            $body[100] = chr(ord($body[100]) ^ 1);
        }

        Http::fake([
            'releases.example.test/latest.json' => Http::response([
                'format' => ReleaseSignature::FORMAT,
                'version' => $version,
                'tag' => 'v'.$version,
                'file' => $file,
                'size' => $size,
                'sha256' => $sha256,
                'php' => '8.5.0',
                'extensions' => ['json'],
                'published' => '2026-11-20T08:00:00Z',
                'signature' => ReleaseSignature::sign(ReleaseSignature::message($version, $file, $size, $sha256), $this->releaseSecretKey),
                ...$manifest,
            ]),
            'releases.example.test/'.$file => Http::response($body),
        ]);
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function writeFiles(string $root, array $files): void
    {
        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            file_put_contents($root.'/'.$path, $content);
        }
    }
}
