<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\User;
use App\Services\Auth\SetupKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ersteinrichtung im Browser (A-06): erstes Konto ohne Terminal, abgesichert über den Einrichtungsschlüssel auf dem
 * Server — nur solange es kein Konto gibt.
 */
class FirstSetupControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/ersteinrichtung-'.getmypid());
        config(['phpdbadmin.setup.key_path' => $this->folder.'/einrichtungsschluessel.txt']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_setup_page_places_key_file_on_the_server(): void
    {
        $this->get(route('setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/FirstSetup')
                ->where('keyPath', app(SetupKey::class)->displayPath()));

        $this->assertFileExists($this->folder.'/einrichtungsschluessel.txt');
    }

    public function test_correct_key_creates_admin_signs_in_and_opens_onboarding(): void
    {
        $this->get(route('setup'));

        $this->post(route('setup.store'), ['name' => 'Hoang Nguyen', 'email' => 'Hoang@Example.com', 'key' => $this->key()])
            ->assertRedirect(route('onboarding'));

        $admin = User::query()->sole();
        $this->assertSame('hoang@example.com', $admin->email);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertAuthenticatedAs($admin);
        $this->assertFileDoesNotExist($this->folder.'/einrichtungsschluessel.txt');
        $this->assertSame(['method' => 'browser'], AuditEntry::query()->where('action', 'account.installed')->sole()->context);
        $this->assertSame(['method' => 'setup'], AuditEntry::query()->where('action', 'auth.login')->sole()->context);
    }

    public function test_wrong_key_creates_no_account_and_is_recorded(): void
    {
        $this->get(route('setup'));

        $this->post(route('setup.store'), ['name' => 'Fremd', 'email' => 'fremd@example.com', 'key' => 'AAAA-AAAA-AAAA'])
            ->assertSessionHasErrors('key');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertFileExists($this->folder.'/einrichtungsschluessel.txt');
        $this->assertDatabaseHas('audit_log', ['action' => 'setup.failed']);
    }

    public function test_key_without_ever_opening_setup_page_is_rejected(): void
    {
        $this->post(route('setup.store'), ['name' => 'Fremd', 'email' => 'fremd@example.com', 'key' => 'AAAA-AAAA-AAAA'])
            ->assertSessionHasErrors('key');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_is_closed_once_an_account_exists(): void
    {
        $this->get(route('setup'));
        $key = $this->key();
        User::factory()->admin()->create();

        $this->get(route('setup'))->assertRedirect(route('login'));
        $this->post(route('setup.store'), ['name' => 'Zweit', 'email' => 'zweit@example.com', 'key' => $key])
            ->assertRedirect(route('login'));

        $this->assertSame(1, User::query()->count());
        $this->assertGuest();
    }

    public function test_requires_name_email_and_key(): void
    {
        $this->post(route('setup.store'), [])->assertSessionHasErrors(['name', 'email', 'key']);
    }

    private function key(): string
    {
        preg_match('/^([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})$/m', (string) file_get_contents($this->folder.'/einrichtungsschluessel.txt'), $match);

        return $match[1];
    }
}
