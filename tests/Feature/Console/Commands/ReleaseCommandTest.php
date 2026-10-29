<?php

namespace Tests\Feature\Console\Commands;

use App\Services\Release\ReleaseBuilder;
use App\Services\Release\ReleaseSignature;
use Tests\Concerns\ReleaseSource;
use Tests\TestCase;

/**
 * `phpdbadmin:release`: Schlüsselpaar einmalig anlegen, Release mit Signatur bauen.
 */
class ReleaseCommandTest extends TestCase
{
    use ReleaseSource;

    private string $keyFolder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyFolder = sys_get_temp_dir().'/phpdbadmin-schluessel-'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $this->cleanUpReleaseSources();
        exec('rm -rf '.escapeshellarg($this->keyFolder));

        parent::tearDown();
    }

    public function test_keygen_writes_a_private_key_only_the_owner_can_read(): void
    {
        $path = $this->keyFolder.'/release.key';

        $this->artisan('phpdbadmin:release', ['--keygen' => $path])
            ->expectsOutputToContain('Öffentlicher Schlüssel')
            ->assertSuccessful();

        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $secret = ReleaseSignature::loadSecretKey($path);
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($secret));
    }

    public function test_keygen_never_overwrites_an_existing_key(): void
    {
        $path = $this->keyFolder.'/release.key';
        mkdir($this->keyFolder, 0700, true);
        file_put_contents($path, 'vorhanden');

        $this->artisan('phpdbadmin:release', ['--keygen' => $path])->assertFailed();

        $this->assertSame('vorhanden', file_get_contents($path));
    }

    public function test_builds_a_signed_release_with_the_key_from_a_file(): void
    {
        $paths = $this->releaseSource();
        $this->app->instance(ReleaseBuilder::class, new ReleaseBuilder($paths['source']));
        $keyPath = $this->keyFolder.'/release.key';
        $publicKey = ReleaseSignature::generate($keyPath);

        $this->artisan('phpdbadmin:release', ['--out' => $paths['out'], '--key' => $keyPath, '--vendor-from' => $paths['vendor']])
            ->expectsOutputToContain('gh release create v'.config('phpdbadmin.version'))
            ->assertSuccessful();

        $manifest = json_decode((string) file_get_contents($paths['out'].'/latest.json'), true);
        $this->assertIsArray($manifest);
        $message = ReleaseSignature::message($manifest['version'], $manifest['file'], $manifest['size'], $manifest['sha256']);
        $this->assertTrue(ReleaseSignature::verify($message, (string) $manifest['signature'], $publicKey));
        $this->assertStringContainsString($publicKey, (string) file_get_contents($paths['out'].'/phpdbadmin-installer.php'));
    }

    public function test_a_wrong_key_stops_the_build(): void
    {
        $paths = $this->releaseSource();
        $this->app->instance(ReleaseBuilder::class, new ReleaseBuilder($paths['source']));

        $this->artisan('phpdbadmin:release', ['--out' => $paths['out'], '--key' => 'kein-schluessel', '--vendor-from' => $paths['vendor']])
            ->expectsOutputToContain('kein Ed25519-Geheimschlüssel')
            ->assertFailed();

        $this->assertFileDoesNotExist($paths['out'].'/latest.json');
    }
}
