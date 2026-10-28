<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * Passkey anlegen und damit anmelden (A-03) — mit einem Authenticator in Software, die Prüfung des Servers läuft
 * vollständig.
 */
class PasskeyLoginControllerTest extends TestCase
{
    use RefreshDatabase;

    private SoftwareAuthenticator $device;

    protected function setUp(): void
    {
        parent::setUp();

        $origin = (string) config('passkeys.allowed_origins')[0];
        $this->device = new SoftwareAuthenticator($origin, (string) config('passkeys.relying_party_id'));
    }

    public function test_registered_passkey_signs_in_and_records_login(): void
    {
        $user = User::factory()->create();
        $this->registerPasskey($user);
        $this->post(route('logout'));

        $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');

        $this->postJson(route('passkey.login'), ['credential' => $this->device->assert($options)])
            ->assertOk()
            ->assertJson(['redirect' => route('home')]);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(['method' => 'passkey'], AuditEntry::query()->where('action', 'auth.login')->sole()->context);
    }

    public function test_unknown_passkey_is_rejected_and_recorded(): void
    {
        $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');

        $this->postJson(route('passkey.login'), ['credential' => $this->device->assert($options)])
            ->assertStatus(422)
            ->assertJsonPath('errors.credential.0', 'Dieser Passkey ist nicht bekannt — vielleicht wurde er im Konto entfernt. Melden Sie sich mit einem Code per Mail an.');

        $this->assertGuest();
        $this->assertSame(['method' => 'passkey'], AuditEntry::query()->where('action', 'auth.failed')->sole()->context);
    }

    private function registerPasskey(User $user): void
    {
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');

        $this->postJson(route('passkey.store'), ['name' => 'MacBook', 'credential' => $this->device->register($options)])
            ->assertOk();
    }
}
