<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Einladungen in der Verwaltung (A-06, Wireframe 14): einladen mit Ablauf, erneut senden, zurückziehen.
 */
class InvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_invites_a_person_with_role_and_expiry(): void
    {
        $this->actingAs($this->admin)->post(route('admin.invitations.store'), [
            'email' => ' Sandra.Kaelin@Kunde-Shop.ch ',
            'role' => 'member',
        ])
            ->assertRedirect(route('admin.invitations.index'))
            ->assertInertiaFlash('invitationLink.email', 'sandra.kaelin@kunde-shop.ch');

        $invitation = Invitation::query()->sole();
        $this->assertSame(UserRole::Member, $invitation->role);
        $this->assertSame($this->admin->id, $invitation->invited_by);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class,
            fn (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'sandra.kaelin@kunde-shop.ch');
        $this->assertDatabaseHas('audit_log', ['action' => 'account.invited', 'user_id' => $this->admin->id, 'target' => 'sandra.kaelin@kunde-shop.ch']);
    }

    public function test_rejects_address_with_an_account_and_missing_fields(): void
    {
        $this->actingAs($this->admin)->post(route('admin.invitations.store'), ['email' => $this->admin->email, 'role' => 'member'])
            ->assertSessionHasErrors(['email' => 'Für diese Adresse gibt es schon ein Konto.']);

        $this->actingAs($this->admin)->post(route('admin.invitations.store'), [])
            ->assertSessionHasErrors(['email' => 'E-Mail-Adresse fehlt.', 'role' => 'Rolle fehlt.']);

        $this->assertDatabaseCount('invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_lists_invitations_with_state(): void
    {
        Invitation::issue('offen@example.ch', UserRole::Member, $this->admin);
        [$expired] = Invitation::issue('abgelaufen@example.ch', UserRole::Admin);
        $expired->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($this->admin)->get(route('admin.invitations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Invitations')
                ->where('tabs.invitations', 1)
                ->where('invitations.0.email', 'abgelaufen@example.ch')
                ->where('invitations.0.state', 'expired')
                ->where('invitations.1.state', 'open')
                ->missing('invitations.0.token_hash'));
    }

    public function test_resending_issues_a_new_link_and_voids_the_old_one(): void
    {
        [$old] = Invitation::issue('sandra@example.ch', UserRole::Member, $this->admin);

        $this->actingAs($this->admin)->post(route('admin.invitations.resend', $old))->assertRedirect(route('admin.invitations.index'));

        $this->assertFalse($old->refresh()->isOpen());
        $this->assertSame(1, Invitation::query()->where('email', 'sandra@example.ch')->get()->filter->isOpen()->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'account.invitation_resent', 'target' => 'sandra@example.ch']);
    }

    public function test_withdrawn_invitation_can_no_longer_be_accepted(): void
    {
        [$invitation, $token] = Invitation::issue('sandra@example.ch', UserRole::Member, $this->admin);

        $this->actingAs($this->admin)->delete(route('admin.invitations.destroy', $invitation))->assertRedirect();
        $this->post(route('logout'));

        $this->post(route('invitation.accept', $token), ['name' => 'Sandra'])->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('users', ['email' => 'sandra@example.ch']);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.invitation_withdrawn']);
    }

    public function test_members_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())->post(route('admin.invitations.store'), ['email' => 'x@example.ch', 'role' => 'admin'])
            ->assertForbidden();

        $this->assertDatabaseCount('invitations', 0);
    }
}
