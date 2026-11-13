<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AuditEntry;
use App\Models\User;
use App\Services\Update\UpdateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\UpdateInstallation;
use Tests\TestCase;

/**
 * Verwaltung · Aktualisierung (B-09): nur Administratoren; Hinweis auf neue Versionen; die vier Schritte über HTTP,
 * der letzte durch den Wartungsmodus hindurch.
 */
class UpdateControllerTest extends TestCase
{
    use RefreshDatabase;
    use UpdateInstallation;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->setUpInstallation();
    }

    protected function tearDown(): void
    {
        $this->tearDownInstallation();

        parent::tearDown();
    }

    public function test_only_administrators_open_the_page_and_run_steps(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('admin.update.index'))->assertForbidden();
        $this->actingAs($member)->postJson(route('admin.update.step', 'download'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.update.check'))->assertForbidden();
    }

    public function test_page_shows_the_installed_version_and_loads_the_status_afterwards(): void
    {
        config(['phpdbadmin.update.check' => true]);
        $this->publishRelease('1.5.0');

        $this->actingAs($this->admin)->get(route('admin.update.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Update')
                ->where('current', '1.4.0')
                ->where('blockers', [])
                ->where('state', null)
                ->missing('status')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('status.latest', '1.5.0')
                    ->where('status.available', true)
                    ->where('status.blockers', [])));
    }

    public function test_without_automatic_checks_the_page_asks_nobody_until_check_now(): void
    {
        $this->publishRelease('1.5.0');

        $this->actingAs($this->admin)->get(route('admin.update.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('automatic', false)
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('status', null)));

        Http::assertNothingSent();
    }

    public function test_administrators_see_a_new_version_everywhere_and_members_do_not(): void
    {
        $this->publishRelease('1.5.0');
        app(UpdateChannel::class)->check();
        $member = User::factory()->create();

        $this->actingAs($this->admin)->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('update.version', '1.5.0'));
        $this->actingAs($member)->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('update', null));
    }

    public function test_without_a_status_phpdbadmin_asks_after_the_response_and_only_when_allowed(): void
    {
        $this->publishRelease('1.5.0');

        $this->actingAs($this->admin)->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('update', null));
        Http::assertNothingSent();

        config(['phpdbadmin.update.check' => true]);
        $this->actingAs($this->admin)->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('update', null));

        Http::assertSentCount(1);
        $this->assertSame('1.5.0', Cache::get(UpdateChannel::CACHE_KEY)['latest'] ?? null);
        $this->actingAs($this->admin)->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('update.version', '1.5.0'));
    }

    public function test_four_steps_update_through_maintenance_mode(): void
    {
        $this->publishRelease('1.5.0');
        $this->actingAs($this->admin);

        $this->postJson(route('admin.update.step', 'download'))->assertOk()->assertJson(['ok' => true, 'version' => '1.5.0']);
        $this->postJson(route('admin.update.step', 'extract'))->assertOk();
        $replaced = $this->postJson(route('admin.update.step', 'replace'))->assertOk()->assertJson(['ok' => true, 'wait' => 0]);
        $bypass = $replaced->getCookie('laravel_maintenance', decrypt: false);
        $this->assertNotNull($bypass);

        // Alle anderen sehen die Wartungsseite.
        $this->get(route('home'))->assertServiceUnavailable();

        config(['phpdbadmin.version' => '1.5.0']);
        $this->withCredentials()
            ->withUnencryptedCookie('laravel_maintenance', $bypass->getValue())
            ->postJson(route('admin.update.step', 'finish'))
            ->assertOk()
            ->assertJson(['ok' => true, 'version' => '1.5.0']);

        $this->get(route('home'))->assertOk();
        $this->assertStringContainsString('1.5.0', (string) file_get_contents($this->core.'/app/Models/Beispiel.php'));
        $this->assertSame(1, AuditEntry::query()->where('action', 'app.updated')->where('user_id', $this->admin->id)->count());
    }

    public function test_finish_with_the_previous_code_asks_the_browser_to_try_again(): void
    {
        $this->publishRelease('1.5.0');
        $this->actingAs($this->admin);
        $this->postJson(route('admin.update.step', 'download'));
        $this->postJson(route('admin.update.step', 'extract'));
        $bypass = $this->postJson(route('admin.update.step', 'replace'))->getCookie('laravel_maintenance', decrypt: false);

        $this->withCredentials()
            ->withUnencryptedCookie('laravel_maintenance', (string) $bypass?->getValue())
            ->postJson(route('admin.update.step', 'finish'))
            ->assertConflict()
            ->assertJson(['ok' => false, 'retry' => true]);
    }

    public function test_a_step_out_of_order_is_refused_with_a_message(): void
    {
        $this->actingAs($this->admin)->postJson(route('admin.update.step', 'extract'))
            ->assertUnprocessable()
            ->assertJson(['ok' => false, 'retry' => false])
            ->assertJsonPath('message', 'Dieser Schritt passt nicht zum Stand der Aktualisierung — bitte die Seite neu laden.');
    }

    public function test_check_now_reports_the_result(): void
    {
        $this->publishRelease('1.5.0');

        $this->actingAs($this->admin)->from(route('admin.update.index'))->post(route('admin.update.check'))
            ->assertRedirect(route('admin.update.index'))
            ->assertInertiaFlash('toast.message', 'Version 1.5.0 ist erschienen.');
    }
}
