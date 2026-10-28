<?php

namespace Tests\Feature\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Konto (A-05): Sicherheit und Darstellung, keine Profilseite.
 */
class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_account_with_passkeys_without_credential_data(): void
    {
        $user = User::factory()->admin()->create(['name' => 'Hoang Nguyen']);
        $user->passkeys()->create(['name' => 'MacBook', 'credential_id' => 'abc', 'credential' => ['publicKey' => 'geheim']]);

        $response = $this->actingAs($user)->get(route('account'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('account/Show')
            ->where('account.name', 'Hoang Nguyen')
            ->where('account.role', 'Administrator')
            ->where('passkeys.0.name', 'MacBook')
            ->missing('passkeys.0.credential')
            ->missing('passkeys.0.credential_id')
            ->where('canManagePasskeys', false)
            ->where('auth.user.email', $user->email)
            ->missing('auth.user.created_at'));
        $this->assertStringNotContainsString('geheim', (string) $response->getContent());
    }

    public function test_recent_confirmation_allows_managing_passkeys(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('account'))
            ->assertInertia(fn (Assert $page) => $page->where('canManagePasskeys', true));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('account'))->assertRedirect(route('login'));
    }
}
