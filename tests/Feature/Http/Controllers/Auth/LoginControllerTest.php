<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\AuditEntry;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Support\Fingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_renders_login_page_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
    }

    public function test_redirects_signed_in_user_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('home'));
    }

    public function test_code_signs_in_with_new_session_and_records_login(): void
    {
        $user = User::factory()->create(['email' => 'nina@example.com']);

        $requested = $this->post(route('login.code'), ['email' => 'Nina@Example.com ']);
        $requested->assertRedirect(route('login.code.show'));
        $sessionBefore = $this->sessionCookie($requested);

        $verified = $this->post(route('login.code.verify'), ['code' => $this->sentCode($user)]);

        $verified->assertRedirect(route('onboarding'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionBefore, $this->sessionCookie($verified));
        $this->assertNotNull($user->fresh()?->last_login_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertSame(['method' => 'code'], AuditEntry::query()->where('action', 'auth.login')->sole()->context);
    }

    public function test_accepts_code_pasted_with_space(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.code'), ['email' => $user->email]);
        $code = $this->sentCode($user);

        $this->post(route('login.code.verify'), ['code' => substr($code, 0, 3).' '.substr($code, 3)])
            ->assertRedirect(route('onboarding'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_with_passkey_goes_straight_to_overview(): void
    {
        $user = User::factory()->create();
        $user->passkeys()->create(['name' => 'MacBook', 'credential_id' => 'abc', 'credential' => ['test' => true]]);
        $this->post(route('login.code'), ['email' => $user->email]);

        $this->post(route('login.code.verify'), ['code' => $this->sentCode($user)])
            ->assertRedirect(route('home'));
    }

    public function test_fresh_installation_shows_setup_instead_of_login(): void
    {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('needsSetup', true));

        User::factory()->create();

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('needsSetup', false));
    }

    public function test_unknown_address_gets_same_answer_without_mail_or_code(): void
    {
        $known = User::factory()->create();

        $forKnown = $this->post(route('login.code'), ['email' => $known->email]);
        $forUnknown = $this->post(route('login.code'), ['email' => 'niemand@example.com']);

        $this->assertSame($forKnown->getStatusCode(), $forUnknown->getStatusCode());
        $this->assertSame($forKnown->headers->get('Location'), $forUnknown->headers->get('Location'));
        $this->get(route('login.code.show'))->assertInertia(fn (Assert $page) => $page
            ->component('auth/Code')
            ->where('email', 'niemand@example.com'));
        Notification::assertSentTimes(LoginCodeNotification::class, 1);
        $this->assertSame(1, LoginCode::query()->count());
    }

    public function test_wrong_code_shows_message_and_records_failed_attempt(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.code'), ['email' => $user->email]);
        $wrong = $this->sentCode($user) === '000000' ? '111111' : '000000';

        $this->post(route('login.code.verify'), ['code' => $wrong])
            ->assertSessionHasErrors(['code' => 'Der Code stimmt nicht oder ist abgelaufen. Fordern Sie bei Bedarf einen neuen an.']);

        $this->assertGuest();
        $entry = AuditEntry::query()->where('action', 'auth.failed')->sole();
        $this->assertSame(Fingerprint::ofEmail($user->email), $entry->context['email_hash'] ?? null);
        $this->assertStringNotContainsString($user->email, (string) json_encode($entry->getAttributes()));
    }

    public function test_wrong_code_message_follows_the_language_of_the_browser(): void
    {
        $this->post(route('login.code'), ['email' => 'nina@example.com']);

        $this->post(route('login.code.verify'), ['code' => '123456'], ['Accept-Language' => 'en-GB,en;q=0.9'])
            ->assertSessionHasErrors(['code' => 'The code is wrong or has expired. Request a new one if needed.']);
    }

    public function test_rejects_code_that_is_not_six_digits(): void
    {
        $this->post(route('login.code'), ['email' => 'nina@example.com']);

        $this->post(route('login.code.verify'), ['code' => '12345'])
            ->assertSessionHasErrors(['code' => 'Code: genau 6 Ziffern eingeben.']);
    }

    public function test_disabled_account_gets_no_code_and_cannot_use_an_earlier_one(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.code'), ['email' => $user->email]);
        $code = $this->sentCode($user);
        $user->forceFill(['disabled_at' => now()])->save();

        $this->post(route('login.code.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        Notification::fake();
        $this->post(route('login.code'), ['email' => $user->email])->assertRedirect(route('login.code.show'));
        Notification::assertNothingSent();
    }

    public function test_rejects_invalid_address(): void
    {
        $this->post(route('login.code'), ['email' => 'kein-mail'])
            ->assertSessionHasErrors(['email' => 'Das ist keine gültige E-Mail-Adresse.']);
    }

    public function test_code_form_without_requested_code_redirects_to_login(): void
    {
        $this->get(route('login.code.show'))->assertRedirect(route('login'));
        $this->post(route('login.code.verify'), ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_throttles_code_requests_per_address_after_five_per_minute(): void
    {
        $user = User::factory()->create();

        for ($request = 1; $request <= 5; $request++) {
            $this->post(route('login.code'), ['email' => $user->email])->assertRedirect(route('login.code.show'));
        }

        $this->from(route('login'))
            ->post(route('login.code'), ['email' => $user->email])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        Notification::assertSentTimes(LoginCodeNotification::class, 5);
    }

    public function test_resend_waits_before_sending_new_code(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.code'), ['email' => $user->email]);

        $this->post(route('login.code.resend'))->assertSessionHasErrors('code');
        Notification::assertSentTimes(LoginCodeNotification::class, 1);

        $this->travel(61)->seconds();

        $this->post(route('login.code.resend'))->assertRedirect(route('login.code.show'));
        Notification::assertSentTimes(LoginCodeNotification::class, 2);
    }

    public function test_logout_ends_session_and_records_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }

    private function sentCode(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, LoginCodeNotification::class, function (LoginCodeNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return $notification->purpose === LoginCode::PURPOSE_LOGIN;
        });

        return (string) $code;
    }

    private function sessionCookie(TestResponse $response): string
    {
        return (string) $response->getCookie((string) config('session.cookie'))?->getValue();
    }
}
