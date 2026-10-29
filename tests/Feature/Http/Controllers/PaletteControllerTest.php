<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suchquelle der Befehlspalette (O-02): nur Verbindungen, die das Konto sieht — eigene und geteilte mit Recht.
 */
class PaletteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_visible_connections_without_secrets(): void
    {
        $user = User::factory()->create();
        DatabaseConnection::factory()->for($user, 'owner')->create(['name' => 'eigene', 'password' => 'Geheim-1']);
        $shared = DatabaseConnection::factory()->for(User::factory()->create(), 'owner')->create(['name' => 'geteilte', 'shared' => true]);
        $shared->members()->attach($user, ['permission' => 'read']);
        DatabaseConnection::factory()->for(User::factory()->create(), 'owner')->create(['name' => 'fremde']);

        $response = $this->actingAs($user)->getJson(route('palette'))->assertOk();

        $this->assertSame(['eigene', 'geteilte'], array_column($response->json('connections'), 'name'));
        $this->assertStringNotContainsString('Geheim-1', (string) $response->getContent());
    }

    public function test_guest_gets_nothing(): void
    {
        $this->getJson(route('palette'))->assertUnauthorized();
    }
}
