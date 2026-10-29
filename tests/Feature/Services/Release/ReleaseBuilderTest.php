<?php

namespace Tests\Feature\Services\Release;

use App\Services\Release\ReleaseBuilder;
use App\Services\Release\ReleaseFailed;
use App\Services\Release\ReleaseSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReleaseSource;
use Tests\TestCase;

/**
 * Release-Archiv für den Web-Installer: nur was eine Installation braucht, Manifest mit Prüfsumme und eine Signatur,
 * die jede Veränderung verrät.
 */
class ReleaseBuilderTest extends TestCase
{
    use ReleaseSource;

    protected function setUp(): void
    {
        parent::setUp();

        config(['phpdbadmin.version' => '1.3.0', 'phpdbadmin.release.repository' => 'hoang-79/phpdbadmin']);
    }

    protected function tearDown(): void
    {
        $this->cleanUpReleaseSources();

        parent::tearDown();
    }

    public function test_archive_holds_what_an_installation_needs_and_nothing_of_the_development(): void
    {
        $paths = $this->releaseSource();

        $result = (new ReleaseBuilder($paths['source']))->build($paths['out'], vendorFrom: $paths['vendor']);

        $entries = $this->zipEntries($result->zipPath);
        $this->assertSame([], array_values(array_filter($entries, fn (string $entry): bool => ! str_starts_with($entry, 'phpdbadmin-1.3.0/'))), 'genau ein oberster Ordner');

        foreach ([
            'app/Models/Beispiel.php', 'bootstrap/app.php', 'bootstrap/phpdbadmin-install.php', 'bootstrap/cache/.gitignore',
            'config/app.php', 'database/migrations/0001_create.php', 'lang/en.json', 'public/index.php', 'public/.htaccess',
            'public/build/manifest.json', 'public/build/assets/app.js', 'resources/views/app.blade.php', 'resources/help/de.md',
            'resources/brand/mail/logo@2x.png', 'routes/web.php', 'storage/app/.gitignore', 'storage/logs/.gitignore',
            'vendor/autoload.php', 'vendor/composer/installed.json', 'artisan', 'composer.json', 'composer.lock', '.env.example',
            'LICENSE', 'README.md',
        ] as $wanted) {
            $this->assertContains('phpdbadmin-1.3.0/'.$wanted, $entries, $wanted.' fehlt');
        }

        foreach ([
            'tests/', 'dokumentationen/', '.github/', '.ai/', 'resources/js/', 'resources/css/', 'resources/installer/',
            'resources/brand/quellen/', 'public/hot', 'storage/logs/laravel.log', 'bootstrap/cache/packages.php',
            'database/database.sqlite', 'package.json', 'phpunit.xml', 'vite.config.ts', 'AGENTS.md', 'CLAUDE.md',
            '.editorconfig', '.env', '.gitignore',
        ] as $unwanted) {
            $this->assertSame(
                [],
                array_values(array_filter($entries, fn (string $entry): bool => $entry === 'phpdbadmin-1.3.0/'.$unwanted || str_starts_with($entry, 'phpdbadmin-1.3.0/'.rtrim($unwanted, '/').'/'))),
                $unwanted.' gehört nicht ins Release',
            );
        }
    }

    public function test_manifest_names_the_archive_with_size_checksum_and_requirements(): void
    {
        $paths = $this->releaseSource();

        $result = (new ReleaseBuilder($paths['source']))->build($paths['out'], vendorFrom: $paths['vendor']);

        $manifest = json_decode((string) file_get_contents($result->manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame('phpdbadmin-release:1', $manifest['format']);
        $this->assertSame('1.3.0', $manifest['version']);
        $this->assertSame('v1.3.0', $manifest['tag']);
        $this->assertSame('phpdbadmin-1.3.0.zip', $manifest['file']);
        $this->assertSame(filesize($result->zipPath), $manifest['size']);
        $this->assertSame(hash_file('sha256', $result->zipPath), $manifest['sha256']);
        $this->assertSame('8.5.0', $manifest['php']);
        $this->assertSame(['pdo_sqlite', 'pdo_mysql', 'pdo_pgsql'], $manifest['drivers']);
        $this->assertSame(['zip'], $manifest['installer_extensions']);
        $this->assertNull($manifest['signature'], 'ohne Schlüssel unsigniert');

        // Aus composer.json, den Paketen ohne Entwicklung und der Systemprüfung — ohne das, was PHP immer hat.
        $this->assertContains('intl', $manifest['extensions']);
        $this->assertContains('mbstring', $manifest['extensions']);
        $this->assertContains('session', $manifest['extensions']);
        $this->assertContains('pdo', $manifest['extensions']);
        $this->assertNotContains('json', $manifest['extensions']);
        $this->assertNotContains('xdebug', $manifest['extensions']);
        $this->assertNotContains('pcov', $manifest['extensions']);
        $this->assertSame($result->manifest, $manifest);
    }

    public function test_signature_is_valid_for_the_published_public_key(): void
    {
        $paths = $this->releaseSource();
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $result = (new ReleaseBuilder($paths['source']))->build($paths['out'], $secret, $paths['vendor']);

        $manifest = $result->manifest;
        $message = ReleaseSignature::message($manifest['version'], $manifest['file'], $manifest['size'], $manifest['sha256']);
        $this->assertNotNull($manifest['signature']);
        $this->assertTrue(ReleaseSignature::verify($message, $manifest['signature'], ReleaseSignature::publicKeyFor($secret)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tamperings(): array
    {
        return [
            'Version' => ['version', '1.3.1'],
            'Datei' => ['file', 'phpdbadmin-1.3.0-anders.zip'],
            'Grösse' => ['size', '1'],
            'Prüfsumme' => ['sha256', str_repeat('0', 64)],
        ];
    }

    #[DataProvider('tamperings')]
    public function test_any_change_to_the_signed_fields_breaks_the_signature(string $field, string $value): void
    {
        $paths = $this->releaseSource();
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $manifest = (new ReleaseBuilder($paths['source']))->build($paths['out'], $secret, $paths['vendor'])->manifest;
        $this->assertNotNull($manifest['signature']);

        $fields = ['version' => $manifest['version'], 'file' => $manifest['file'], 'size' => (string) $manifest['size'], 'sha256' => $manifest['sha256']];
        $fields[$field] = $value;
        $message = ReleaseSignature::message($fields['version'], $fields['file'], (int) $fields['size'], $fields['sha256']);

        $this->assertFalse(ReleaseSignature::verify($message, $manifest['signature'], ReleaseSignature::publicKeyFor($secret)));
    }

    public function test_signature_does_not_fit_a_foreign_public_key(): void
    {
        $paths = $this->releaseSource();
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $foreign = ReleaseSignature::publicKeyFor(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $manifest = (new ReleaseBuilder($paths['source']))->build($paths['out'], $secret, $paths['vendor'])->manifest;
        $this->assertNotNull($manifest['signature']);

        $message = ReleaseSignature::message($manifest['version'], $manifest['file'], $manifest['size'], $manifest['sha256']);
        $this->assertFalse(ReleaseSignature::verify($message, $manifest['signature'], $foreign));
    }

    public function test_installer_gets_the_public_key_and_the_repository(): void
    {
        $paths = $this->releaseSource();
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $result = (new ReleaseBuilder($paths['source']))->build($paths['out'], $secret, $paths['vendor']);

        $this->assertNotNull($result->installerPath);
        $installer = (string) file_get_contents($result->installerPath);
        $this->assertStringContainsString("const SCHLUESSEL = '".ReleaseSignature::publicKeyFor($secret)."';", $installer);
        $this->assertStringContainsString("const REPO = 'hoang-79/phpdbadmin';", $installer);
        $this->assertStringNotContainsString('__PHPDBADMIN_', $installer);
    }

    public function test_unsigned_release_leaves_the_key_of_the_installer_empty(): void
    {
        $paths = $this->releaseSource();

        $result = (new ReleaseBuilder($paths['source']))->build($paths['out'], vendorFrom: $paths['vendor']);

        $this->assertNotNull($result->installerPath);
        $this->assertStringContainsString("const SCHLUESSEL = '';", (string) file_get_contents($result->installerPath));
    }

    public function test_without_installer_template_the_release_is_built_anyway(): void
    {
        $paths = $this->releaseSource(withInstaller: false);

        $result = (new ReleaseBuilder($paths['source']))->build($paths['out'], vendorFrom: $paths['vendor']);

        $this->assertNull($result->installerPath);
        $this->assertFileExists($result->zipPath);
    }

    public function test_refuses_to_build_without_the_built_interface(): void
    {
        $paths = $this->releaseSource(withBuild: false);

        $this->expectException(ReleaseFailed::class);
        $this->expectExceptionMessage('npm run build');

        (new ReleaseBuilder($paths['source']))->build($paths['out'], vendorFrom: $paths['vendor']);
    }

    public function test_file_selection_of_this_repository_ships_the_runtime_and_leaves_the_rest(): void
    {
        $files = (new ReleaseBuilder)->files();

        foreach (['app/Models/User.php', 'bootstrap/phpdbadmin-install.php', 'config/phpdbadmin.php', 'lang/en.json',
            'public/index.php', 'resources/views/app.blade.php', 'resources/brand/mail/logo@2x.png', 'resources/help/de.md',
            'routes/web.php', 'artisan', 'composer.lock', '.env.example', 'LICENSE'] as $wanted) {
            $this->assertContains($wanted, $files, $wanted.' fehlt im Release');
        }

        $this->assertSame([], array_values(array_filter($files, fn (string $file): bool => preg_match('#^(tests|dokumentationen|\.github|node_modules|vendor|resources/js|resources/css|resources/installer)/#', $file) === 1)));
        $this->assertNotContains('phpunit.xml', $files);
        $this->assertNotContains('package.json', $files);
    }
}
