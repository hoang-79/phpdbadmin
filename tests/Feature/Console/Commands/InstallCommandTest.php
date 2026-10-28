<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_exactly_one_admin_account(): void
    {
        $this->artisan('phpdbadmin:install', ['--name' => 'Hoang Nguyen', '--email' => 'Hoang@Example.com'])
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('hoang@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.installed', 'user_id' => $user->id]);
    }

    public function test_prints_setup_link_that_leads_into_onboarding(): void
    {
        $this->artisan('phpdbadmin:install', ['--name' => 'Hoang Nguyen', '--email' => 'hoang@example.com'])
            ->expectsOutputToContain(url('/setup/'))
            ->assertSuccessful();
    }

    public function test_issues_new_link_while_first_setup_is_unfinished(): void
    {
        User::factory()->admin()->create(['last_login_at' => null]);

        $this->artisan('phpdbadmin:install')
            ->expectsOutputToContain('noch nicht abgeschlossen')
            ->expectsOutputToContain(url('/setup/'))
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
    }

    public function test_refuses_when_first_setup_is_done(): void
    {
        User::factory()->admin()->create(['last_login_at' => now()]);

        $this->artisan('phpdbadmin:install', ['--name' => 'Zweit', '--email' => 'zweit@example.com'])
            ->expectsOutputToContain('Es gibt schon ein Konto')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_rejects_invalid_address(): void
    {
        $this->artisan('phpdbadmin:install', ['--name' => 'Hoang', '--email' => 'kein-mail'])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
