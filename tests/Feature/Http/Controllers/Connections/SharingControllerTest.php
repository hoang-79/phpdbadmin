<?php

namespace Tests\Feature\Http\Controllers\Connections;

use App\Enums\ConnectionPermission;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verbindungen teilen (V-09): nur Administratoren, Rechte je Konto, persönliche Verbindungen bleiben für andere 404.
 */
class SharingControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Hoang Nguyen']);
        $this->member = User::factory()->create(['name' => 'Nina Brügger']);
    }

    public function test_admin_shares_own_connection_with_a_member(): void
    {
        $connection = DatabaseConnection::factory()->for($this->admin, 'owner')->create(['name' => 'shop']);

        $this->actingAs($this->admin)->put(route('connections.sharing.update', $connection), [
            'shared' => true,
            'permissions' => [$this->member->id => 'write'],
        ])->assertRedirect();

        $this->assertTrue($connection->refresh()->shared);
        $this->assertSame(ConnectionPermission::Write, $connection->permissionFor($this->member));
        $this->assertDatabaseHas('audit_log', ['action' => 'connection.shared', 'user_id' => $this->admin->id, 'target' => $connection->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'connection.permission', 'target' => $this->member->email]);
        // Sichtbar (403 statt 404), aber mit «schreiben» nicht zu bearbeiten.
        $this->actingAs($this->member)->get(route('connections.edit', $connection))->assertForbidden();
    }

    public function test_sharing_dialog_lists_accounts_with_their_permission_but_no_password(): void
    {
        $connection = DatabaseConnection::factory()->create(['shared' => true, 'password' => 'Geheim-Teilen-1']);
        $connection->members()->attach($this->member, ['permission' => ConnectionPermission::Read->value]);

        $response = $this->actingAs($this->admin)->getJson(route('connections.sharing.show', $connection));

        $response->assertOk()
            ->assertJsonPath('shared', true)
            ->assertJsonFragment(['id' => $this->member->id, 'permission' => 'read', 'isOwner' => false])
            ->assertJsonFragment(['id' => $connection->owner_id, 'permission' => null, 'isOwner' => true]);
        $this->assertStringNotContainsString('Geheim-Teilen-1', (string) $response->getContent());
    }

    public function test_no_access_removes_the_permission(): void
    {
        $connection = DatabaseConnection::factory()->create(['shared' => true]);
        $connection->members()->attach($this->member, ['permission' => ConnectionPermission::Manage->value]);

        $this->actingAs($this->admin)->put(route('connections.sharing.update', $connection), [
            'shared' => true,
            'permissions' => [$this->member->id => null],
        ])->assertRedirect();

        $this->assertDatabaseMissing('connection_user', ['connection_id' => $connection->id, 'user_id' => $this->member->id]);
        $this->actingAs($this->member)->get(route('connections.edit', $connection))->assertNotFound();
    }

    public function test_unsharing_makes_the_connection_personal_and_404_for_members(): void
    {
        $owner = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($owner, 'owner')->create(['shared' => true]);
        $connection->members()->attach($this->member, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($this->admin)->put(route('connections.sharing.update', $connection), ['shared' => false])
            ->assertRedirect();

        $this->assertFalse($connection->refresh()->shared);
        $this->assertDatabaseMissing('connection_user', ['connection_id' => $connection->id, 'user_id' => $this->member->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'connection.unshared', 'target' => $connection->id]);
        $this->actingAs($this->member)->get(route('workspace', $connection))->assertNotFound();
        $this->actingAs($this->admin)->get(route('connections.edit', $connection))->assertNotFound();
        $this->actingAs($owner)->get(route('connections.edit', $connection))->assertOk();
    }

    public function test_owner_and_admins_get_no_stored_permission(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $connection = DatabaseConnection::factory()->create(['shared' => true]);

        $this->actingAs($this->admin)->put(route('connections.sharing.update', $connection), [
            'shared' => true,
            'permissions' => [$connection->owner_id => 'read', $otherAdmin->id => 'read'],
        ])->assertRedirect();

        $this->assertDatabaseCount('connection_user', 0);
        $this->assertDatabaseMissing('audit_log', ['action' => 'connection.permission']);
    }

    public function test_member_cannot_share_even_own_connection(): void
    {
        $connection = DatabaseConnection::factory()->for($this->member, 'owner')->create();

        $this->actingAs($this->member)->getJson(route('connections.sharing.show', $connection))->assertForbidden();
        $this->actingAs($this->member)->put(route('connections.sharing.update', $connection), ['shared' => true])->assertForbidden();
        $this->assertFalse($connection->refresh()->shared);
    }

    public function test_personal_connection_of_another_account_is_404_for_admins(): void
    {
        $connection = DatabaseConnection::factory()->for($this->member, 'owner')->create();

        $this->actingAs($this->admin)->getJson(route('connections.sharing.show', $connection))->assertNotFound();
        $this->actingAs($this->admin)->put(route('connections.sharing.update', $connection), ['shared' => true])->assertNotFound();
        $this->assertFalse($connection->refresh()->shared);
    }

    public function test_rejects_unknown_permission_and_missing_flag(): void
    {
        $connection = DatabaseConnection::factory()->for($this->admin, 'owner')->create();

        $this->actingAs($this->admin)->put(route('connections.sharing.update', $connection), [
            'permissions' => [$this->member->id => 'alles'],
        ])->assertSessionHasErrors([
            'shared' => 'Teilen fehlt.',
            'permissions.'.$this->member->id => 'Recht: dieser Wert ist nicht erlaubt.',
        ]);

        $this->assertFalse($connection->refresh()->shared);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $connection = DatabaseConnection::factory()->create(['shared' => true]);

        $this->put(route('connections.sharing.update', $connection), ['shared' => false])->assertRedirect(route('login'));
    }
}
