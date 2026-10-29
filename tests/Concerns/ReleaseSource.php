<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Kleines Git-Repository als Quelle für `phpdbadmin:release`: von allem etwas — was ins Release gehört und was nicht.
 */
trait ReleaseSource
{
    /** @var list<string> */
    private array $releaseFolders = [];

    /**
     * @return array{source: string, vendor: string, out: string}
     */
    protected function releaseSource(bool $withBuild = true, bool $withInstaller = true): array
    {
        $base = sys_get_temp_dir().'/phpdbadmin-release-test-'.bin2hex(random_bytes(5));
        $this->releaseFolders[] = $base;
        $source = $base.'/quelle';

        $files = [
            'app/Models/Beispiel.php' => '<?php // App',
            'bootstrap/app.php' => '<?php // App',
            'bootstrap/phpdbadmin-install.php' => '<?php // Brücke',
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
            'bootstrap/cache/packages.php' => '<?php // Zwischenstand der Entwicklung',
            'config/app.php' => '<?php return [];',
            'database/migrations/0001_create.php' => '<?php // Migration',
            'database/.gitignore' => "*.sqlite*\n",
            'database/database.sqlite' => 'Daten der Entwicklung',
            'lang/en.json' => '{}',
            'public/index.php' => '<?php // Front',
            'public/.htaccess' => 'RewriteEngine On',
            'public/hot' => 'http://localhost:5173',
            'resources/views/app.blade.php' => '<html></html>',
            'resources/help/de.md' => '# Hilfe',
            'resources/brand/mail/logo@2x.png' => 'PNG',
            'resources/brand/quellen/bauen.py' => '# Generator',
            'resources/js/app.ts' => 'export {}',
            'resources/css/app.css' => 'body {}',
            'routes/web.php' => '<?php // Routen',
            'storage/app/.gitignore' => "*\n!.gitignore\n",
            'storage/logs/.gitignore' => "*\n!.gitignore\n",
            'storage/logs/laravel.log' => 'Protokoll der Entwicklung',
            'tests/ExampleTest.php' => '<?php // Test',
            'dokumentationen/README.md' => '# Doku',
            '.github/workflows/tests.yml' => 'name: tests',
            '.ai/rules/index.md' => '# Regeln',
            'artisan' => '#!/usr/bin/env php',
            'composer.json' => (string) json_encode(['require' => ['php' => '^8.5', 'ext-intl' => '*'], 'require-dev' => ['ext-xdebug' => '*']]),
            'composer.lock' => (string) json_encode([
                'packages' => [['name' => 'laravel/framework', 'require' => ['ext-mbstring' => '*', 'ext-json' => '*', 'ext-session' => '*']]],
                'packages-dev' => [['name' => 'dev/werkzeug', 'require' => ['ext-pcov' => '*']]],
            ]),
            'package.json' => '{}',
            'phpunit.xml' => '<phpunit/>',
            'vite.config.ts' => 'export default {}',
            'AGENTS.md' => '# Agenten',
            'CLAUDE.md' => '# Claude',
            '.editorconfig' => 'root = true',
            '.env' => 'APP_KEY=geheim',
            '.env.example' => 'APP_KEY=',
            '.gitignore' => "/vendor\n/public/build\n/public/hot\n.env\n",
            'LICENSE' => 'MIT',
            'README.md' => '# phpdbadmin',
        ];

        if ($withBuild) {
            $files['public/build/manifest.json'] = '{}';
            $files['public/build/assets/app.js'] = 'console.log(1)';
        }

        if ($withInstaller) {
            $files['resources/installer/phpdbadmin-installer.php'] = "<?php\nconst SCHLUESSEL = '__PHPDBADMIN_PUBLIC_KEY__';\nconst REPO = '__PHPDBADMIN_REPOSITORY__';\n";
        }

        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($source.'/'.$path));
            file_put_contents($source.'/'.$path, $content);
        }

        (new Process(['git', 'init', '-q'], $source))->mustRun();

        $vendor = $base.'/vendor';
        File::ensureDirectoryExists($vendor.'/composer');
        file_put_contents($vendor.'/autoload.php', '<?php // Autoload');
        file_put_contents($vendor.'/composer/installed.json', '{"packages":[]}');

        return ['source' => $source, 'vendor' => $vendor, 'out' => $base.'/ausgabe'];
    }

    protected function cleanUpReleaseSources(): void
    {
        foreach ($this->releaseFolders as $folder) {
            File::deleteDirectory($folder);
        }

        $this->releaseFolders = [];
    }

    /**
     * @return list<string>
     */
    protected function zipEntries(string $zipPath): array
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }

        $zip->close();

        return $entries;
    }
}
