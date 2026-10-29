<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ein gesperrtes Konto ist mit der nächsten Anfrage abgemeldet (Verwaltung, Wireframe 14).
 */
class EnsureAccountIsActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_account_is_signed_out_on_its_next_request(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);

        $this->actingAs($user)->get(route('home'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Dieses Konto ist gesperrt. Wenden Sie sich an eine Administratorin oder einen Administrator.']);

        $this->assertGuest();
    }

    public function test_active_account_keeps_working(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();

        $this->assertAuthenticated();
    }
}
