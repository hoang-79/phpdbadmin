<?php

namespace Tests\Feature\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Passkey;
use Tests\Concerns\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * Passkeys verwalten im Konto (A-03): hinzufügen und entfernen über die Bibliothek, umbenennen über
 * PasskeyController — alles erst nach einer Bestätigung.
 */
class PasskeyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_user_adds_passkey_and_it_is_recorded(): void
    {
        $user = User::factory()->create();
        $device = new SoftwareAuthenticator((string) config('passkeys.allowed_origins')[0], (string) config('passkeys.relying_party_id'));
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
        $this->postJson(route('passkey.store'), ['name' => 'MacBook', 'credential' => $device->register($options)])
            ->assertOk()
            ->assertJson(['status' => 'passkey-registered', 'name' => 'MacBook']);

        $this->assertSame(['MacBook'], $user->passkeys()->pluck('name')->all());
        $this->assertDatabaseHas('audit_log', ['action' => 'passkey.registered', 'user_id' => $user->id, 'target' => 'MacBook']);
    }

    public function test_unconfirmed_session_must_confirm_before_adding_passkey(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson(route('passkey.registration-options'))->assertStatus(423);
        $this->get(route('passkey.registration-options'))->assertRedirect(route('password.confirm'));
    }

    public function test_renames_own_passkey(): void
    {
        $user = User::factory()->create();
        $passkey = $this->passkeyFor($user, 'Alt');

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('passkey.update', $passkey), ['name' => '  iPhone  '])
            ->assertRedirect(route('account'));

        $this->assertSame('iPhone', $passkey->fresh()?->name);
    }

    public function test_rename_requires_name(): void
    {
        $user = User::factory()->create();
        $passkey = $this->passkeyFor($user, 'Alt');

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('passkey.update', $passkey), ['name' => ''])
            ->assertSessionHasErrors(['name' => 'Name fehlt.']);
    }

    public function test_returns_404_when_renaming_passkey_of_other_account(): void
    {
        $passkey = $this->passkeyFor(User::factory()->create(), 'Fremd');

        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('passkey.update', $passkey), ['name' => 'Meins'])
            ->assertNotFound();

        $this->assertSame('Fremd', $passkey->fresh()?->name);
    }

    public function test_removes_own_passkey_but_not_one_of_other_account(): void
    {
        $user = User::factory()->create();
        $own = $this->passkeyFor($user, 'Eigen');
        $foreign = $this->passkeyFor(User::factory()->create(), 'Fremd');
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->delete(route('passkey.destroy', $foreign))->assertForbidden();
        $this->delete(route('passkey.destroy', $own))->assertRedirect();

        $this->assertModelMissing($own);
        $this->assertModelExists($foreign);
        $this->assertDatabaseHas('audit_log', ['action' => 'passkey.deleted', 'target' => 'Eigen']);
    }

    private function passkeyFor(User $user, string $name): Passkey
    {
        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => bin2hex(random_bytes(16)),
            'credential' => ['test' => true],
        ]);
    }
}
