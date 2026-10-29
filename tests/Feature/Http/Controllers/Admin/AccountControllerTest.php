<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\ConnectionPermission;
use App\Enums\UserRole;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Verwaltung der Konten (Wireframe 14, A-05, A-07): nur Administratoren; Name und Adresse, Rolle, Sperre, Entfernen
 * und Rechte je Verbindung — jede Änderung im Protokoll.
 */
class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Hoang Nguyen']);
        $this->member = User::factory()->create(['name' => 'Nina Brügger', 'email' => 'nina@kunde-shop.ch', 'last_login_at' => now()]);
    }

    public function test_lists_accounts_with_role_last_login_and_passkeys(): void
    {
        $this->actingAs($this->admin)->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Accounts')
                ->where('tabs.accounts', 2)
                ->where('accounts.0.name', 'Hoang Nguyen')
                ->where('accounts.0.isAdmin', true)
                ->where('accounts.0.isSelf', true)
                ->where('accounts.1.email', 'nina@kunde-shop.ch')
                ->where('accounts.1.hasPasskeys', false)
                ->whereType('accounts.1.lastLoginAt', 'string')
                ->where('selected', null));
    }

    public function test_account_page_shows_permission_per_shared_connection_but_no_personal_connection_of_others(): void
    {
        $shared = DatabaseConnection::factory()->create(['name' => 'shop', 'shared' => true]);
        $shared->members()->attach($this->member, ['permission' => ConnectionPermission::Write->value]);
        DatabaseConnection::factory()->for($this->admin, 'owner')->create(['name' => 'basalto']);
        DatabaseConnection::factory()->for($this->member, 'owner')->create(['name' => 'ninas-eigene']);

        $this->actingAs($this->admin)->get(route('admin.accounts.show', $this->member))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.id', $this->member->id)
                ->where('selected.can.delete', true)
                ->has('rights', 2)
                ->where('rights.0.name', 'shop')
                ->where('rights.0.permission', 'write')
                ->where('rights.0.editable', true)
                ->where('rights.1.name', 'basalto')
                ->where('rights.1.shared', false)
                ->where('rights.1.editable', false));
    }

    public function test_admin_changes_name_and_email_of_an_account(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.accounts.update', $this->member), [
            'name' => 'Nina Muster',
            'email' => ' Nina.Muster@Kunde-Shop.ch ',
        ])->assertRedirect();

        $this->member->refresh();
        $this->assertSame('Nina Muster', $this->member->name);
        $this->assertSame('nina.muster@kunde-shop.ch', $this->member->email);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.updated', 'user_id' => $this->admin->id, 'target' => 'nina.muster@kunde-shop.ch']);
    }

    public function test_rejects_email_of_another_account_and_empty_fields(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.accounts.update', $this->member), [
            'name' => 'Nina',
            'email' => strtoupper((string) $this->admin->email),
        ])->assertSessionHasErrors(['email' => 'E-Mail-Adresse ist schon vergeben.']);

        $this->actingAs($this->admin)->patch(route('admin.accounts.update', $this->member), [])
            ->assertSessionHasErrors(['name' => 'Name fehlt.', 'email' => 'E-Mail-Adresse fehlt.']);

        $this->assertSame('nina@kunde-shop.ch', $this->member->refresh()->email);
    }

    public function test_admin_promotes_a_member(): void
    {
        $this->actingAs($this->admin)->put(route('admin.accounts.role', $this->member), ['role' => 'admin'])
            ->assertRedirect();

        $this->assertSame(UserRole::Admin, $this->member->refresh()->role);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.role', 'target' => $this->member->email]);
    }

    public function test_demoted_admin_keeps_no_rights_on_connections_of_others(): void
    {
        $formerAdmin = User::factory()->admin()->create();
        $shared = DatabaseConnection::factory()->create(['shared' => true]);
        $own = DatabaseConnection::factory()->for($formerAdmin, 'owner')->create(['shared' => true]);
        $shared->markFavorite($formerAdmin, true);
        $own->markFavorite($formerAdmin, true);

        $this->actingAs($this->admin)->put(route('admin.accounts.role', $formerAdmin), ['role' => 'member'])
            ->assertRedirect();

        $this->assertNull($shared->permissionFor($formerAdmin->refresh()));
        $this->assertNull($shared->membershipOf($formerAdmin));
        $this->assertTrue($own->membershipOf($formerAdmin)?->favorite);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.role', 'target' => $formerAdmin->email]);
    }

    public function test_own_role_status_and_account_cannot_be_changed(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('admin.accounts.role', $this->admin), ['role' => 'member'])->assertForbidden();
        $this->put(route('admin.accounts.status', $this->admin), ['disabled' => true])->assertForbidden();
        $this->delete(route('admin.accounts.destroy', $this->admin))->assertForbidden();

        $this->admin->refresh();
        $this->assertTrue($this->admin->isAdmin());
        $this->assertFalse($this->admin->isDisabled());
    }

    public function test_disabling_ends_sessions_and_is_recorded(): void
    {
        DB::table('sessions')->insert(['id' => 'sitzung-nina', 'user_id' => $this->member->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->put(route('admin.accounts.status', $this->member), ['disabled' => true])
            ->assertRedirect();

        $this->assertTrue($this->member->refresh()->isDisabled());
        $this->assertDatabaseMissing('sessions', ['id' => 'sitzung-nina']);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.disabled', 'target' => $this->member->email]);

        $this->put(route('admin.accounts.status', $this->member), ['disabled' => false])->assertRedirect();
        $this->assertFalse($this->member->refresh()->isDisabled());
    }

    public function test_removing_an_account_keeps_its_shared_connections(): void
    {
        $shared = DatabaseConnection::factory()->for($this->member, 'owner')->create(['shared' => true]);
        $personal = DatabaseConnection::factory()->for($this->member, 'owner')->create();

        $this->actingAs($this->admin)->delete(route('admin.accounts.destroy', $this->member))
            ->assertRedirect(route('admin.accounts.index'));

        $this->assertModelMissing($this->member);
        $this->assertModelMissing($personal);
        $this->assertSame($this->admin->id, $shared->refresh()->owner_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.deleted', 'target' => 'nina@kunde-shop.ch']);
    }

    public function test_sets_permissions_only_on_shared_connections(): void
    {
        $shared = DatabaseConnection::factory()->create(['shared' => true]);
        $personal = DatabaseConnection::factory()->for($this->admin, 'owner')->create();

        $this->actingAs($this->admin)->put(route('admin.accounts.permissions', $this->member), [
            'permissions' => [$shared->id => 'read', $personal->id => 'manage'],
        ])->assertRedirect();

        $this->assertSame(ConnectionPermission::Read, $shared->permissionFor($this->member));
        $this->assertNull($personal->permissionFor($this->member));
        $this->assertDatabaseCount('connection_user', 1);
    }

    public function test_rejects_unknown_permission(): void
    {
        $shared = DatabaseConnection::factory()->create(['shared' => true]);

        $this->actingAs($this->admin)->put(route('admin.accounts.permissions', $this->member), [
            'permissions' => [$shared->id => 'alles'],
        ])->assertSessionHasErrors(['permissions.'.$shared->id => 'Recht: dieser Wert ist nicht erlaubt.']);

        $this->assertDatabaseCount('connection_user', 0);
    }

    public function test_members_are_forbidden(): void
    {
        $this->actingAs($this->member)->get(route('admin.accounts.index'))->assertForbidden();
        $this->actingAs($this->member)->patch(route('admin.accounts.update', $this->member), ['name' => 'Selbst', 'email' => 'selbst@example.ch'])
            ->assertForbidden();

        $this->assertSame('Nina Brügger', $this->member->refresh()->name);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.accounts.index'))->assertRedirect(route('login'));
    }
}
