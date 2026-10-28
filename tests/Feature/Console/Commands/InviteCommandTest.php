<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InviteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_invitation_as_member_by_default(): void
    {
        Notification::fake();

        $this->artisan('phpdbadmin:invite', ['email' => 'nina@example.com'])->assertSuccessful();

        $invitation = Invitation::query()->sole();
        $this->assertSame(UserRole::Member, $invitation->role);
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            return $notifiable->routes['mail'] === 'nina@example.com'
                && str_starts_with($notification->url, url('/invitation/'));
        });
    }

    public function test_admin_option_invites_administrator(): void
    {
        Notification::fake();

        $this->artisan('phpdbadmin:invite', ['email' => 'nina@example.com', '--admin' => true])->assertSuccessful();

        $this->assertSame(UserRole::Admin, Invitation::query()->sole()->role);
    }

    public function test_refuses_address_with_existing_account(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'nina@example.com']);

        $this->artisan('phpdbadmin:invite', ['email' => 'nina@example.com'])->assertFailed();

        Notification::assertNothingSent();
    }
}
