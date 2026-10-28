<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ersteinrichtung über den signierten Link aus phpdbadmin:install (A-06): gilt 30 Minuten und nur bis zur ersten
 * Anmeldung des ersten Administratorkontos.
 */
class SetupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_shows_setup_page_without_signing_in(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Hoang Nguyen']);

        $this->get($this->link($admin))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Setup')
                ->where('setup.name', 'Hoang Nguyen')
                ->where('setup.email', $admin->email));

        $this->assertGuest();
    }

    public function test_starting_setup_signs_in_and_opens_onboarding(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post($this->link($admin))->assertRedirect(route('onboarding'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull($admin->fresh()?->last_login_at);
        $this->assertSame(['method' => 'setup'], AuditEntry::query()->where('action', 'auth.login')->sole()->context);
    }

    public function test_link_is_worthless_after_first_sign_in(): void
    {
        $admin = User::factory()->admin()->create();
        $link = $this->link($admin);
        $this->post($link);
        $this->post(route('logout'));

        $this->post($link)->assertRedirect($link);
        $this->assertGuest();
        $this->get($link)->assertInertia(fn (Assert $page) => $page->where('setup', null));
    }

    public function test_rejects_expired_or_tampered_link(): void
    {
        $admin = User::factory()->admin()->create();
        $link = $this->link($admin);

        $this->post($link.'x')->assertRedirect();
        $this->assertGuest();

        $this->travel(31)->minutes();

        $this->post($link)->assertRedirect();
        $this->assertGuest();
        $this->get($link)->assertInertia(fn (Assert $page) => $page->where('setup', null));
    }

    public function test_link_does_not_work_for_members(): void
    {
        $member = User::factory()->create();

        $this->post($this->link($member))->assertRedirect();

        $this->assertGuest();
    }

    private function link(User $user): string
    {
        return URL::temporarySignedRoute('setup.show', now()->addMinutes(30), ['user' => $user->id]);
    }
}
