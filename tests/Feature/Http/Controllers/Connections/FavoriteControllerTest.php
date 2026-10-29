<?php

namespace Tests\Feature\Http\Controllers\Connections;

use App\Enums\ConnectionPermission;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Favoriten je Konto (V-06): setzen und entfernen, ohne dass sich dabei ein Recht ändert.
 */
class FavoriteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_marks_personal_connection_as_favorite_and_removes_it_again(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($user, 'owner')->create();

        $this->actingAs($user)->put(route('connections.favorite', $connection), ['favorite' => true])->assertRedirect();

        $this->assertTrue($connection->membershipOf($user)?->favorite);
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('connections.0.favorite', true));

        $this->put(route('connections.favorite', $connection), ['favorite' => false])->assertRedirect();

        $this->assertFalse($connection->membershipOf($user)?->favorite);
    }

    public function test_favorite_keeps_the_permission_of_a_member(): void
    {
        $user = User::factory()->create();
        $shared = DatabaseConnection::factory()->create(['shared' => true]);
        $shared->members()->attach($user, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($user)->put(route('connections.favorite', $shared), ['favorite' => true])->assertRedirect();

        $membership = $shared->membershipOf($user);
        $this->assertTrue($membership?->favorite);
        $this->assertSame(ConnectionPermission::Read, $membership->permission);
        $this->assertFalse($user->can('write', $shared));
    }

    public function test_favorite_of_one_account_is_not_shown_to_another(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $shared = DatabaseConnection::factory()->create(['shared' => true]);
        $shared->members()->attach($member, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($admin)->put(route('connections.favorite', $shared), ['favorite' => true])->assertRedirect();

        $this->actingAs($member)->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('connections.0.favorite', false));
    }

    public function test_foreign_connection_is_404(): void
    {
        $connection = DatabaseConnection::factory()->create();

        $this->actingAs(User::factory()->create())->put(route('connections.favorite', $connection), ['favorite' => true])
            ->assertNotFound();

        $this->assertDatabaseCount('connection_user', 0);
    }

    public function test_rejects_missing_flag(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($user, 'owner')->create();

        $this->actingAs($user)->put(route('connections.favorite', $connection), [])
            ->assertSessionHasErrors(['favorite' => 'Favorit fehlt.']);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->put(route('connections.favorite', DatabaseConnection::factory()->create()), ['favorite' => true])
            ->assertRedirect(route('login'));
    }
}
