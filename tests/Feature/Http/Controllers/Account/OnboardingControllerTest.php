<?php

namespace Tests\Feature\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Onboarding nach der ersten Anmeldung: Passkey gleich anlegen; das erste Konto sieht die Ersteinrichtung.
 */
class OnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_of_installation_sees_first_setup(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('onboarding'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Welcome')
                ->where('isFirstUser', true)
                ->where('hasPasskey', false)
                ->where('canRegister', true)
                ->where('continueUrl', route('home')));
    }

    public function test_invited_member_sees_welcome_without_first_setup(): void
    {
        User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('onboarding'))
            ->assertInertia(fn (Assert $page) => $page->where('isFirstUser', false));
    }

    public function test_without_recent_confirmation_registration_waits_and_returns_here(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('onboarding'))
            ->assertInertia(fn (Assert $page) => $page->where('canRegister', false))
            ->assertSessionHas('url.intended', route('onboarding'));
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('onboarding'))->assertRedirect(route('login'));
    }
}
