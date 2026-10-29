<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\LoginCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aufbewahrung (Datenmodell § 4): Anmeldecodes einen Tag, Einladungen 30 Tage nach Ablauf.
 */
class PruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_login_codes_and_invitations_are_removed_after_their_period(): void
    {
        $oldCode = LoginCode::query()->create(['purpose' => 'login', 'email_hash' => 'a', 'code_hash' => 'x', 'attempts' => 0, 'expires_at' => now()->subDays(2)]);
        $freshCode = LoginCode::query()->create(['purpose' => 'login', 'email_hash' => 'b', 'code_hash' => 'y', 'attempts' => 0, 'expires_at' => now()->subHour()]);
        [$oldInvitation] = Invitation::issue('alt@example.com', UserRole::Member);
        $oldInvitation->forceFill(['expires_at' => now()->subDays(31)])->save();
        [$openInvitation] = Invitation::issue('offen@example.com', UserRole::Member);

        $this->artisan('model:prune', ['--model' => [LoginCode::class, Invitation::class]])->assertSuccessful();

        $this->assertModelMissing($oldCode);
        $this->assertModelExists($freshCode);
        $this->assertModelMissing($oldInvitation);
        $this->assertModelExists($openInvitation);
    }
}
