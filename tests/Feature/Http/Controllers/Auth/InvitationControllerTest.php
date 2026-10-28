<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Einladung statt Registrierung (A-06).
 */
class InvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_open_invitation(): void
    {
        [, $token] = Invitation::issue('nina@example.com', UserRole::Member);

        $this->get(route('invitation.show', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Invitation')
                ->where('invitation.email', 'nina@example.com')
                ->where('invitation.role', 'Mitglied'));
    }

    public function test_accepting_creates_account_with_role_and_signs_in(): void
    {
        [$invitation, $token] = Invitation::issue('nina@example.com', UserRole::Admin);

        $this->post(route('invitation.accept', $token), ['name' => 'Nina Brügger'])
            ->assertRedirect(route('account'));

        $user = User::query()->where('email', 'nina@example.com')->sole();
        $this->assertSame('Nina Brügger', $user->name);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($invitation->fresh()?->accepted_at);
        $this->assertSame(['method' => 'invitation'], AuditEntry::query()->where('action', 'auth.login')->sole()->context);
    }

    public function test_rejects_expired_invitation(): void
    {
        [, $token] = Invitation::issue('nina@example.com', UserRole::Member);
        $this->travel(7)->days();
        $this->travel(1)->minutes();

        $this->get(route('invitation.show', $token))
            ->assertInertia(fn (Assert $page) => $page->where('invitation', null));
        $this->post(route('invitation.accept', $token), ['name' => 'Nina'])
            ->assertSessionHasErrors(['name' => 'Diese Einladung ist abgelaufen oder wurde schon angenommen.']);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nina@example.com']);
    }

    public function test_rejects_invitation_that_was_already_accepted(): void
    {
        [, $token] = Invitation::issue('nina@example.com', UserRole::Member);
        $this->post(route('invitation.accept', $token), ['name' => 'Nina']);
        $this->post(route('logout'));

        $this->post(route('invitation.accept', $token), ['name' => 'Jemand anderes'])->assertSessionHasErrors('name');

        $this->assertGuest();
        $this->assertSame('Nina', User::query()->sole()->name);
    }

    public function test_new_invitation_replaces_open_one_for_same_address(): void
    {
        [, $first] = Invitation::issue('nina@example.com', UserRole::Member);
        Invitation::issue('nina@example.com', UserRole::Member);

        $this->post(route('invitation.accept', $first), ['name' => 'Nina'])->assertSessionHasErrors('name');
    }

    public function test_unknown_token_shows_no_invitation(): void
    {
        $this->get(route('invitation.show', 'unbekannt'))
            ->assertInertia(fn (Assert $page) => $page->where('invitation', null));
    }

    public function test_accepting_requires_name(): void
    {
        [, $token] = Invitation::issue('nina@example.com', UserRole::Member);

        $this->post(route('invitation.accept', $token), ['name' => ''])->assertSessionHasErrors(['name' => 'Name fehlt.']);
    }
}
