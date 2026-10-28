<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * Bestätigen vor dem Verwalten von Passkeys — per Code an die eigene Adresse oder per Passkey (ersetzt die
 * Passwortbestätigung, F-6).
 */
class ConfirmControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_confirm_page_with_own_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('password.confirm'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Confirm')
                ->where('email', $user->email)
                ->where('hasPasskeys', false));
    }

    public function test_code_to_own_address_confirms_and_returns_to_intended_page(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('passkey.registration-options'))->assertRedirect(route('password.confirm'));

        $this->post(route('confirm.code'))->assertRedirect(route('password.confirm'));

        $code = null;
        Notification::assertSentTo($user, LoginCodeNotification::class, function (LoginCodeNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return $notification->purpose === LoginCode::PURPOSE_CONFIRM;
        });

        $this->post(route('confirm.code.verify'), ['code' => $code])
            ->assertRedirect(route('passkey.registration-options'));
        $this->getJson(route('passkey.registration-options'))->assertOk();
    }

    public function test_wrong_code_does_not_confirm(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('confirm.code'));

        $this->post(route('confirm.code.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('confirm.code.verify'), ['code' => '111111'])->assertSessionHasErrors('code');

        $this->getJson(route('passkey.registration-options'))->assertStatus(423);
    }

    public function test_login_code_cannot_be_used_to_confirm(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('login.code'), ['email' => $user->email]);
        $loginCode = null;
        Notification::assertSentTo($user, LoginCodeNotification::class, function (LoginCodeNotification $notification) use (&$loginCode): bool {
            $loginCode = $notification->code;

            return true;
        });

        $this->actingAs($user)->post(route('confirm.code.verify'), ['code' => $loginCode])->assertSessionHasErrors('code');
    }

    public function test_passkey_confirms_session(): void
    {
        $user = User::factory()->create();
        $device = new SoftwareAuthenticator((string) config('passkeys.allowed_origins')[0], (string) config('passkeys.relying_party_id'));
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $options = $this->getJson(route('passkey.registration-options'))->json('options');
        $this->postJson(route('passkey.store'), ['name' => 'MacBook', 'credential' => $device->register($options)])->assertOk();
        $this->withSession(['auth.password_confirmed_at' => 0]);
        $this->getJson(route('passkey.registration-options'))->assertStatus(423);

        $options = $this->getJson(route('passkey.confirm-options'))->assertOk()->json('options');
        $this->postJson(route('passkey.confirm'), ['credential' => $device->assert($options)])->assertOk();

        $this->getJson(route('passkey.registration-options'))->assertOk();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('password.confirm'))->assertRedirect(route('login'));
    }
}
