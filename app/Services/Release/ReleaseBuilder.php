<?php

namespace App\Services\Release;

use App\Services\Setup\SystemCheck;
use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Release-Archiv für den Web-Installer: `phpdbadmin-<version>.zip` mit genau einem obersten Ordner
 * `phpdbadmin-<version>/`, darin alles, was eine Installation braucht — die versionierten Dateien ohne Doku, Tests und
 * Werkzeuge der Entwicklung, die gebaute Oberfläche (`public/build`) und `vendor` ohne Entwicklungspakete. Daneben
 * `latest.json` mit Grösse, Prüfsumme und Ed25519-Signatur, und der Installer mit dem öffentlichen Schlüssel.
 *
 * Gebaut wird auf dem Rechner des Herstellers (`php artisan phpdbadmin:release`), nicht in GitHub Actions: Der
 * Geheimschlüssel verlässt den Rechner nie, und Releases kosten keine Actions-Minuten.
 */
final class ReleaseBuilder
{
    /** Oberste Ebene: nur das, was eine Installation braucht — alles andere (Doku, Tests, Werkzeuge) bleibt draussen. */
    private const array TOP_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'storage'];

    private const array TOP_FILES = ['artisan', 'composer.json', 'composer.lock', '.env.example', 'LICENSE', 'README.md'];

    /** Innerhalb dieser Ordner nicht ausgeliefert: Quellen der Oberfläche und des Logos, der Installer selbst. */
    private const array EXCLUDED_PREFIXES = [
        'resources/js/',
        'resources/css/',
        'resources/installer/',
        'resources/brand/quellen/',
        'public/build/',
        'public/hot',
        'public/storage',
        'public/fonts-manifest.dev.json',
    ];

    /** Hat PHP 8 immer — gehört nicht in die Liste, die der Installer prüft. */
    private const array CORE_EXTENSIONS = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard'];

    /** Mindestens einer davon für die App-Datenbank. */
    private const array DRIVERS = ['pdo_sqlite', 'pdo_mysql', 'pdo_pgsql'];

    /** Braucht nur der Installer selbst (Archiv entpacken). */
    private const array INSTALLER_EXTENSIONS = ['zip'];

    private readonly string $source;

    public function __construct(?string $source = null)
    {
        $this->source = rtrim($source ?? base_path(), DIRECTORY_SEPARATOR);
    }

    /**
     * @param  non-empty-string|null  $secretKey  Ed25519-Geheimschlüssel (64 Bytes); ohne Schlüssel bleibt das Release unsigniert
     * @param  string|null  $vendorFrom  fertiger `vendor`-Ordner statt `composer install` (Tests, schnelle Proben)
     * @param  (Closure(string): void)|null  $progress
     *
     * @throws ReleaseFailed
     */
    public function build(string $outDirectory, ?string $secretKey = null, ?string $vendorFrom = null, ?Closure $progress = null): ReleaseResult
    {
        $progress ??= static function (string $step): void {};
        $version = (string) config('phpdbadmin.version');
        $name = 'phpdbadmin-'.$version;

        if (! is_file($this->source.'/public/build/manifest.json')) {
            throw new ReleaseFailed('Die Oberfläche ist nicht gebaut (public/build fehlt) — zuerst npm run build.');
        }

        if ($vendorFrom !== null && ! is_file($vendorFrom.'/autoload.php')) {
            throw new ReleaseFailed(strtr(':path ist kein vendor-Ordner (autoload.php fehlt).', [':path' => $vendorFrom]));
        }

        File::ensureDirectoryExists($outDirectory);
        $staging = sys_get_temp_dir().'/phpdbadmin-release-'.bin2hex(random_bytes(6));
        $root = $staging.'/'.$name;

        try {
            $progress('Dateien zusammenstellen');
            foreach ($this->files() as $file) {
                File::ensureDirectoryExists(dirname($root.'/'.$file));
                copy($this->source.'/'.$file, $root.'/'.$file);
            }
            File::copyDirectory($this->source.'/public/build', $root.'/public/build');

            if ($vendorFrom !== null) {
                $progress('vendor übernehmen');
                File::copyDirectory($vendorFrom, $root.'/vendor');
            } else {
                $progress('composer install --no-dev');
                $this->installVendor($root);
            }

            $progress('Archiv packen');
            $zipPath = $outDirectory.'/'.$name.'.zip';
            $this->zip($root, $name, $zipPath);
        } finally {
            File::deleteDirectory($staging);
        }

        $size = (int) filesize($zipPath);
        $sha256 = (string) hash_file('sha256', $zipPath);
        $file = basename($zipPath);
        $signature = $secretKey === null ? null : ReleaseSignature::sign(ReleaseSignature::message($version, $file, $size, $sha256), $secretKey);

        $manifest = [
            'format' => ReleaseSignature::FORMAT,
            'version' => $version,
            'tag' => 'v'.$version,
            'file' => $file,
            'size' => $size,
            'sha256' => $sha256,
            'php' => $this->minimumPhp(),
            'extensions' => $this->extensions(),
            'drivers' => self::DRIVERS,
            'installer_extensions' => self::INSTALLER_EXTENSIONS,
            'published' => Date::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'signature' => $signature,
        ];

        $manifestPath = $outDirectory.'/latest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

        $progress('Installer vorbereiten');
        $installerPath = $this->installer($outDirectory, $secretKey === null ? '' : ReleaseSignature::publicKeyFor($secretKey));

        return new ReleaseResult($zipPath, $manifestPath, $installerPath, $manifest);
    }

    /**
     * Versionierte und neue, nicht ignorierte Dateien, die eine Installation braucht.
     *
     * @return list<string>
     *
     * @throws ReleaseFailed
     */
    public function files(): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $this->source, timeout: 60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ReleaseFailed(strtr('git ls-files ist gescheitert — der Release-Befehl braucht ein Git-Repository: :reason', [':reason' => trim($process->getErrorOutput())]));
        }

        $files = array_filter(
            array_unique(explode("\0", $process->getOutput())),
            fn (string $file): bool => $file !== '' && $this->belongs($file) && is_file($this->source.'/'.$file) && ! is_link($this->source.'/'.$file),
        );
        sort($files);

        return $files;
    }

    private function belongs(string $file): bool
    {
        $top = explode('/', $file, 2)[0];

        if (! str_contains($file, '/')) {
            return in_array($file, self::TOP_FILES, true);
        }

        if (! in_array($top, self::TOP_DIRECTORIES, true)) {
            return false;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return false;
            }
        }

        // Daten der Entwicklung: keine Datenbank-Dateien, aus storage und bootstrap/cache nur das Gerüst.
        if (preg_match('#^database/[^/]+\.sqlite#', $file) === 1) {
            return false;
        }

        if (str_starts_with($file, 'storage/') || str_starts_with($file, 'bootstrap/cache/')) {
            return basename($file) === '.gitignore';
        }

        return true;
    }

    /**
     * @throws ReleaseFailed
     */
    private function installVendor(string $root): void
    {
        $composer = (new ExecutableFinder)->find('composer');

        if ($composer === null) {
            throw new ReleaseFailed('composer nicht gefunden — oder --vendor-from=<ordner> angeben.');
        }

        // Pfad-Pakete kopieren statt verlinken: Ein ZIP kennt keine symbolischen Verknüpfungen.
        $process = new Process(
            [$composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--no-scripts'],
            $root,
            ['COMPOSER_MIRROR_PATH_REPOS' => '1'],
            timeout: 900,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ReleaseFailed(strtr('composer install ist gescheitert: :reason', [':reason' => trim($process->getErrorOutput())]));
        }
    }

    /**
     * @throws ReleaseFailed
     */
    private function zip(string $root, string $name, string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ReleaseFailed(strtr('Das Archiv :path lässt sich nicht anlegen.', [':path' => $zipPath]));
        }

        $paths = [];
        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $paths[substr($item->getPathname(), strlen($root) + 1)] = $item;
        }
        ksort($paths, SORT_STRING);

        foreach ($paths as $relative => $item) {
            $entry = $name.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($item->isDir()) {
                $zip->addEmptyDir($entry);

                continue;
            }

            $zip->addFile($item->getPathname(), $entry);
            // Unix-Rechte mitgeben: artisan ausführbar, sonst lesbar.
            $mode = $relative === 'artisan' ? 0100755 : 0100644;
            $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, $mode << 16);
        }

        if (! $zip->close()) {
            throw new ReleaseFailed(strtr('Das Archiv :path liess sich nicht schreiben.', [':path' => $zipPath]));
        }
    }

    /**
     * Den Installer mit öffentlichem Schlüssel und Repository daneben legen — fehlt er (noch), nur ohne.
     */
    private function installer(string $outDirectory, string $publicKey): ?string
    {
        $template = $this->source.'/resources/installer/phpdbadmin-installer.php';

        if (! is_file($template)) {
            return null;
        }

        $path = $outDirectory.'/phpdbadmin-installer.php';
        file_put_contents($path, strtr((string) file_get_contents($template), [
            '__PHPDBADMIN_PUBLIC_KEY__' => $publicKey,
            '__PHPDBADMIN_REPOSITORY__' => (string) config('phpdbadmin.release.repository'),
        ]));

        return $path;
    }

    /**
     * Untergrenze aus `composer.json` («^8.5» → 8.5.0).
     */
    private function minimumPhp(): string
    {
        $composer = json_decode((string) file_get_contents($this->source.'/composer.json'), true);
        $constraint = is_array($composer) ? (string) ($composer['require']['php'] ?? '') : '';

        if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $constraint, $match) !== 1) {
            return '8.5.0';
        }

        return $match[1].'.'.$match[2].'.'.($match[3] ?? '0');
    }

    /**
     * Erweiterungen, die phpdbadmin zur Laufzeit braucht: aus den Paketen ohne Entwicklung (`composer.lock`),
     * `composer.json` und der Systemprüfung — ohne die, die PHP 8 immer hat.
     *
     * @return list<string>
     */
    private function extensions(): array
    {
        $extensions = SystemCheck::REQUIRED_EXTENSIONS;
        $lock = json_decode((string) @file_get_contents($this->source.'/composer.lock'), true);
        $composer = json_decode((string) @file_get_contents($this->source.'/composer.json'), true);
        $requirements = [is_array($composer) ? ($composer['require'] ?? []) : []];

        foreach (is_array($lock) ? ($lock['packages'] ?? []) : [] as $package) {
            $requirements[] = is_array($package) ? ($package['require'] ?? []) : [];
        }

        foreach ($requirements as $require) {
            foreach (array_keys(is_array($require) ? $require : []) as $name) {
                if (is_string($name) && str_starts_with($name, 'ext-')) {
                    $extensions[] = strtolower(substr($name, 4));
                }
            }
        }

        $extensions = array_values(array_diff(array_unique($extensions), self::CORE_EXTENSIONS));
        sort($extensions);

        return $extensions;
    }
}
