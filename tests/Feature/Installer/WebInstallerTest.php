<?php

namespace Tests\Feature\Installer;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * Der Web-Installer (resources/installer/phpdbadmin-installer.php) in einem echten PHP-Server: ein Release aus
 * diesem Ordner einrichten — und alles ablehnen, was nicht echt, nicht unversehrt oder nicht in Reihenfolge ist.
 * Das Release ist klein und künstlich; die Brücke darin schreibt nur auf, womit sie gerufen wurde.
 */
class WebInstallerTest extends TestCase
{
    private string $base;

    private string $docroot;

    private string $home;

    private string $url;

    private ?Process $server = null;

    private string $secretKey;

    private string $cookie = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Ohne symbolische Links (macOS: /var → /private/var) — so sieht der Installer seine Pfade (__DIR__).
        $this->base = realpath(sys_get_temp_dir()).'/phpdbadmin-installer-test-'.bin2hex(random_bytes(5));
        $this->home = $this->base.'/home';
        $this->docroot = $this->home.'/www';
        mkdir($this->docroot, 0755, true);

        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $installer = (string) file_get_contents(resource_path('installer/phpdbadmin-installer.php'));
        file_put_contents($this->docroot.'/phpdbadmin-installer.php', strtr($installer, [
            '__PHPDBADMIN_PUBLIC_KEY__' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            '__PHPDBADMIN_REPOSITORY__' => 'example/phpdbadmin',
        ]));

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $this->url = 'http://127.0.0.1:'.$port;
        $this->server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $this->docroot], $this->docroot, ['HOME' => $this->home]);
        $this->server->start();
        for ($attempt = 0; $attempt < 50 && @fsockopen('127.0.0.1', $port) === false; $attempt++) {
            usleep(100_000);
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        exec('rm -rf '.escapeshellarg($this->base));

        parent::tearDown();
    }

    /**
     * Release neben den Installer legen: ZIP mit dem nötigsten Aufbau, latest.json mit Grösse, Prüfsumme, Signatur.
     *
     * @param  array<string, string>  $extra  zusätzliche Einträge im ZIP (Pfad => Inhalt)
     * @param  array<string, mixed>  $manifest  Felder, die nach dem Signieren überschrieben werden
     */
    private function release(array $extra = [], array $manifest = [], bool $sign = true, ?string $tamperZip = null): void
    {
        $zipPath = $this->docroot.'/phpdbadmin-9.9.9.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = [
            'artisan' => '#!/usr/bin/env php',
            'bootstrap/app.php' => '<?php',
            'bootstrap/phpdbadmin-install.php' => "<?php\nreturn static function (array \$input): array {\n"
                ."    file_put_contents(__DIR__.'/../bridge-input.json', json_encode(\$input));\n"
                ."    return ['version' => '9.9.9', 'setupUrl' => 'http://example.test/setup/1', 'existing' => false, 'checks' => [['title' => 'PHP', 'level' => 'ok', 'hint' => null]]];\n};\n",
            'vendor/autoload.php' => '<?php',
            'public/index.php' => '<?php // Laravel',
            'public/.htaccess' => "<IfModule mod_rewrite.c>\n    RewriteEngine On\n</IfModule>\n",
            'public/build/manifest.json' => '{}',
            'public/favicon.svg' => '<svg/>',
            'database/.gitignore' => '*.sqlite*',
            'storage/logs/.gitignore' => '*',
            ...$extra,
        ];
        foreach ($files as $path => $content) {
            $zip->addFromString(str_starts_with($path, '../') ? $path : 'phpdbadmin-9.9.9/'.$path, $content);
        }
        $zip->close();

        $size = (int) filesize($zipPath);
        $sha = (string) hash_file('sha256', $zipPath);
        $message = "phpdbadmin-release:1\n9.9.9\nphpdbadmin-9.9.9.zip\n".$size."\n".$sha;
        $data = [
            'format' => 'phpdbadmin-release:1', 'version' => '9.9.9', 'tag' => 'v9.9.9', 'file' => 'phpdbadmin-9.9.9.zip', 'size' => $size, 'sha256' => $sha,
            'signature' => $sign ? base64_encode(sodium_crypto_sign_detached($message, $this->secretKey)) : null,
            ...$manifest,
        ];
        file_put_contents($this->docroot.'/latest.json', (string) json_encode($data));

        if ($tamperZip !== null) {
            $bytes = (string) file_get_contents($zipPath);
            file_put_contents($zipPath, substr_replace($bytes, $tamperZip, 40, strlen($tamperZip)));
        }
    }

    /** Seite öffnen: Der Installer setzt das Token als Cookie — wie im Browser. */
    private function open(): Response
    {
        $response = Http::get($this->url.'/phpdbadmin-installer.php');
        if (preg_match('/pda_owner=([0-9a-f]{64})/', (string) $response->header('Set-Cookie'), $match) === 1) {
            $this->cookie = $match[1];
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function action(string $action, array $fields = [], ?string $cookie = null): array
    {
        $cookie ??= $this->cookie;
        $response = Http::asForm()->withHeaders(['Cookie' => 'pda_owner='.$cookie])
            ->post($this->url.'/phpdbadmin-installer.php', ['action' => $action, 'token' => $cookie, ...$this->form(), ...$fields]);

        return (array) $response->json() + ['status' => $response->status()];
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return ['name' => 'Ada Muster', 'email' => 'Ada@Example.CH', 'locale' => 'de', 'db' => 'sqlite', 'mail' => 'later', 'location' => 'outside', 'allowed_hosts' => '127.0.0.1,::1,localhost'];
    }

    public function test_installs_a_signed_release_outside_the_web_folder_and_removes_itself(): void
    {
        $this->release();
        $this->assertTrue($this->open()->successful());

        foreach (['download', 'extract', 'configure', 'place'] as $step) {
            $this->assertTrue($this->action($step)['ok'] ?? false, $step);
        }
        $setup = $this->action('setup');
        $this->assertTrue($setup['ok']);
        $this->assertSame('http://example.test/setup/1', $setup['setupUrl']);
        $this->assertTrue($this->action('finish')['ok']);

        $core = $this->home.'/phpdbadmin-app/127.0.0.1';
        $this->assertFileExists($core.'/artisan');
        $this->assertSame(['name' => 'Ada Muster', 'email' => 'ada@example.ch', 'locale' => 'de', 'publicPath' => $this->docroot],
            json_decode((string) file_get_contents($core.'/bridge-input.json'), true));

        $env = (string) file_get_contents($core.'/.env');
        $this->assertStringContainsString('APP_URL='.$this->url."\n", $env);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('DB_DATABASE='.$core.'/database/database.sqlite', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $env);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($core.'/.env')), -4));

        $index = (string) file_get_contents($this->docroot.'/index.php');
        $this->assertStringContainsString('phpdbadmin-installer:index', $index);
        $this->assertStringContainsString(var_export($core, true), $index);
        $this->assertFileExists($this->docroot.'/build/manifest.json');
        $this->assertStringContainsString('phpdbadmin-guard', (string) file_get_contents($this->docroot.'/.htaccess'));

        foreach (['phpdbadmin-installer.php', '.phpdbadmin-installer-state.php', '.phpdbadmin-download.zip', '.phpdbadmin-staging', 'latest.json', 'phpdbadmin-9.9.9.zip', '.env'] as $gone) {
            $this->assertFileDoesNotExist($this->docroot.'/'.$gone);
        }
    }

    public function test_page_carries_the_favicon_of_the_brand_kit(): void
    {
        $favicon = 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents(resource_path('brand/favicon.svg')));

        $this->assertStringContainsString('<link rel="icon" type="image/svg+xml" href="'.$favicon.'">', $this->open()->body());
    }

    public function test_altered_archive_is_refused_before_anything_is_unpacked(): void
    {
        $this->release(tamperZip: 'verändert');
        $this->open();

        $result = $this->action('download');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Prüfsumme', (string) $result['error']);
        $this->assertFileDoesNotExist($this->docroot.'/.phpdbadmin-download.zip');
        $this->assertFalse($this->action('extract')['ok']);
        $this->assertDirectoryDoesNotExist($this->docroot.'/.phpdbadmin-staging');
    }

    public function test_manifest_that_does_not_match_its_signature_is_refused(): void
    {
        $this->release(manifest: ['version' => '9.9.10']);
        $this->open();

        $result = $this->action('download');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Signatur', (string) $result['error']);
        $this->assertFileDoesNotExist($this->docroot.'/.phpdbadmin-download.zip');
    }

    public function test_unsigned_release_is_refused_when_the_installer_carries_a_key(): void
    {
        $this->release(sign: false);
        $this->open();

        $result = $this->action('download');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('nicht signiert', (string) $result['error']);
    }

    public function test_archive_with_a_path_outside_its_folder_is_not_unpacked(): void
    {
        $this->release(extra: ['../ausbruch.php' => '<?php echo "draussen";']);
        $this->open();
        $this->assertTrue($this->action('download')['ok']);

        $result = $this->action('extract');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unzulässigen Pfad', (string) $result['error']);
        $this->assertFileDoesNotExist($this->docroot.'/ausbruch.php');
        $this->assertFileDoesNotExist($this->docroot.'/.phpdbadmin-staging/ausbruch.php');
    }

    public function test_only_the_first_browser_operates_the_installer(): void
    {
        $this->release();
        $this->open();
        $this->assertTrue($this->action('download')['ok']);

        $other = $this->action('extract', cookie: str_repeat('a', 64));

        $this->assertSame(409, $other['status']);
        $this->assertTrue($other['busy']);
        $this->assertDirectoryDoesNotExist($this->docroot.'/.phpdbadmin-staging');
    }

    public function test_steps_run_only_in_order(): void
    {
        $this->release();
        $this->open();
        $this->assertTrue($this->action('download')['ok']);

        $result = $this->action('place');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('zu früh', (string) $result['error']);
        $this->assertFileDoesNotExist($this->docroot.'/index.php');
    }

    public function test_foreign_files_block_the_installation_until_they_are_moved_aside(): void
    {
        file_put_contents($this->docroot.'/index.html', 'Platzhalter des Hosters');
        $this->release();

        $this->assertStringContainsString('data-problem="files"', $this->open()->body());
        $moved = $this->action('fix_move');

        $this->assertTrue($moved['ok']);
        $this->assertFileDoesNotExist($this->docroot.'/index.html');
        $this->assertCount(1, glob($this->docroot.'/.phpdbadmin-vorher-*/index.html') ?: []);
        $this->assertStringNotContainsString('data-problem="files"', $this->open()->body());
    }
}
