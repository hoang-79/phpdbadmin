<?php

namespace Tests\Feature\Services\Setup;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Setup\InstallFailed;
use App\Services\Setup\WebInstall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Einrichtung durch den Web-Installer: App-Datenbank, erstes Administratorkonto in der gewählten Sprache,
 * Einrichtungslink, Systemprüfung — und die Brücke `bootstrap/phpdbadmin-install.php` in einem eigenen PHP-Prozess.
 */
class WebInstallTest extends TestCase
{
    use RefreshDatabase;

    private ?string $folder = null;

    protected function tearDown(): void
    {
        if ($this->folder !== null) {
            exec('rm -rf '.escapeshellarg($this->folder));
        }

        parent::tearDown();
    }

    public function test_creates_the_first_admin_in_the_chosen_language_with_a_working_setup_link(): void
    {
        $result = app(WebInstall::class)->run(['name' => 'Hoang Nguyen', 'email' => 'Hoang@Example.com', 'locale' => 'en', 'publicPath' => null]);

        $user = User::query()->sole();
        $this->assertSame('hoang@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertSame('en', $user->locale);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.installed', 'user_id' => $user->id]);

        $this->assertFalse($result['existing']);
        $this->assertSame(config('phpdbadmin.version'), $result['version']);
        $this->assertNotNull($result['setupUrl']);
        $this->get($result['setupUrl'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Setup')->where('setup.email', 'hoang@example.com'));
    }

    public function test_returns_the_system_check_in_a_plain_shape(): void
    {
        $result = app(WebInstall::class)->run(['name' => 'Hoang', 'email' => 'hoang@example.com', 'locale' => 'de']);

        $this->assertNotSame([], $result['checks']);
        foreach ($result['checks'] as $check) {
            $this->assertSame(['title', 'level', 'hint'], array_keys($check));
            $this->assertContains($check['level'], ['ok', 'warn', 'fail']);
        }
    }

    public function test_second_run_creates_no_further_account_but_renews_the_link_until_the_first_sign_in(): void
    {
        app(WebInstall::class)->run(['name' => 'Hoang', 'email' => 'hoang@example.com', 'locale' => 'de']);

        $again = app(WebInstall::class)->run(['name' => 'Andere', 'email' => 'andere@example.com', 'locale' => 'de']);

        $this->assertTrue($again['existing']);
        $this->assertSame(1, User::query()->count());
        $this->assertNotNull($again['setupUrl'], 'noch nie angemeldet: neuer Link');

        User::query()->sole()->forceFill(['last_login_at' => now()])->save();
        $this->assertNull(app(WebInstall::class)->run(['name' => 'X', 'email' => 'x@example.com', 'locale' => 'de'])['setupUrl']);
    }

    public function test_invalid_mail_address_stops_with_a_clear_message_in_the_chosen_language(): void
    {
        $this->expectException(InstallFailed::class);
        $this->expectExceptionMessage('kein-mail');

        try {
            app(WebInstall::class)->run(['name' => 'Hoang', 'email' => 'kein-mail', 'locale' => 'de']);
        } finally {
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_missing_name_stops_the_installation(): void
    {
        $this->expectException(InstallFailed::class);

        app(WebInstall::class)->run(['name' => '', 'email' => 'hoang@example.com', 'locale' => 'de']);
    }

    public function test_bridge_sets_up_a_fresh_sqlite_installation_in_its_own_process(): void
    {
        $this->folder = sys_get_temp_dir().'/phpdbadmin-bruecke-'.bin2hex(random_bytes(5));
        mkdir($this->folder);
        $database = $this->folder.'/app.sqlite';
        $key = 'base64:'.base64_encode(random_bytes(32));

        $first = $this->runBridge(['name' => 'Probe', 'email' => 'Probe@Example.com', 'locale' => 'en', 'publicPath' => null], $database, $key);

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $result = json_decode($first->getOutput(), true);
        $this->assertIsArray($result);
        $this->assertFalse($result['existing']);
        $this->assertSame(config('phpdbadmin.version'), $result['version']);
        $this->assertNotSame([], $result['checks']);

        // Link mit der Wurzel aus APP_URL (Unterordner) und gültiger Signatur über den Schlüssel der Installation.
        $this->assertStringStartsWith('https://db.example.ch/phpdbadmin/setup/1?expires=', (string) $result['setupUrl']);
        [$unsigned, $signature] = explode('&signature=', (string) $result['setupUrl']);
        $this->assertSame(hash_hmac('sha256', $unsigned, $key), $signature);

        $pdo = new PDO('sqlite:'.$database);
        $row = $pdo->query('select email, role, locale from users')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['email' => 'probe@example.com', 'role' => 'admin', 'locale' => 'en'], $row);

        $second = $this->runBridge(['name' => 'Zweit', 'email' => 'zweit@example.com', 'locale' => 'de', 'publicPath' => null], $database, $key);
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $this->assertTrue(json_decode($second->getOutput(), true)['existing']);
        $this->assertSame(1, (int) $pdo->query('select count(*) from users')->fetchColumn());
    }

    public function test_bridge_reports_an_invalid_mail_address_as_install_failure(): void
    {
        $this->folder = sys_get_temp_dir().'/phpdbadmin-bruecke-'.bin2hex(random_bytes(5));
        mkdir($this->folder);

        $process = $this->runBridge(['name' => 'Probe', 'email' => 'kein-mail', 'locale' => 'de', 'publicPath' => null], $this->folder.'/app.sqlite', 'base64:'.base64_encode(random_bytes(32)));

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('InstallFailed', $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('kein-mail', $process->getErrorOutput().$process->getOutput());
    }

    /**
     * Die Brücke so aufrufen, wie es der Installer tut: `require` und die Funktion — in einem eigenen PHP-Prozess mit
     * eigener `.env`-Umgebung (Umgebungsvariablen gehen der `.env` vor).
     *
     * @param  array<string, mixed>  $input
     */
    private function runBridge(array $input, string $database, string $key): Process
    {
        $code = '$install = require '.var_export(base_path('bootstrap/phpdbadmin-install.php'), true).';'
            .' echo json_encode($install('.var_export($input, true).'));';

        $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => $key,
            'APP_URL' => 'https://db.example.ch/phpdbadmin',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'null',
        ], timeout: 120);
        $process->run();

        return $process;
    }
}
