<?php

namespace Tests\Feature\Http\Controllers\Connections;

use App\Enums\ConnectionEnvironment;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Verbindungen anlegen, bearbeiten, entfernen (V-01, V-03, V-05) und sehen (V-09, T-16).
 */
class ConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/connections-'.getmypid());
        @mkdir($this->folder, 0777, true);
        touch($this->folder.'/notizen.sqlite');

        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
            'phpdbadmin.connections.connect_timeout' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_overview_lists_own_and_shared_connections_but_not_foreign_ones(): void
    {
        $user = User::factory()->create();
        DatabaseConnection::factory()->for($user, 'owner')->create(['name' => 'eigene']);
        $shared = DatabaseConnection::factory()->create(['name' => 'geteilte', 'shared' => true]);
        $shared->members()->attach($user, ['permission' => ConnectionPermission::Read->value]);
        DatabaseConnection::factory()->create(['name' => 'fremde']);
        DatabaseConnection::factory()->create(['name' => 'nicht-geteilt-trotz-recht', 'shared' => false])
            ->members()->attach($user, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($user)->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('connections/Index')
                ->has('connections', 2)
                ->where('connections.0.name', 'eigene')
                ->where('connections.1.name', 'geteilte')
                ->where('connections.1.canManage', false));
    }

    public function test_overview_never_contains_passwords(): void
    {
        $user = User::factory()->create();
        DatabaseConnection::factory()->for($user, 'owner')->create(['password' => 'Geheim-Uebersicht-1']);

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk();
        $this->assertStringNotContainsString('Geheim-Uebersicht-1', (string) $response->getContent());
    }

    public function test_stores_server_connection_with_encrypted_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('connections.store'), [
            'name' => 'shop',
            'group' => 'Kunde Shop',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'shop',
            'username' => 'shop_lesen',
            'password' => 'Geheim-Speichern-2',
            'store_password' => true,
            'sslmode' => 'require',
            'environment' => 'test',
            'read_only' => false,
        ])->assertRedirect(route('home'));

        $connection = DatabaseConnection::query()->sole();
        $this->assertSame($user->id, $connection->owner_id);
        $this->assertSame(DatabaseDriver::MySql, $connection->driver);
        $this->assertSame('Geheim-Speichern-2', $connection->password);
        $this->assertSame(['sslmode' => 'require'], $connection->options);
        $raw = (string) DB::table('connections')->value('password');
        $this->assertNotSame('Geheim-Speichern-2', $raw);
        $this->assertStringNotContainsString('Geheim-Speichern-2', $raw);
        $this->assertDatabaseHas('audit_log', ['action' => 'connection.created', 'target' => $connection->id]);
    }

    public function test_does_not_store_password_when_asked_not_to(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), [
            'name' => 'shop', 'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '1', 'username' => 'app',
            'password' => 'Geheim-Nicht-3', 'store_password' => false, 'environment' => 'local',
        ])->assertRedirect(route('home'));

        $this->assertNull(DB::table('connections')->value('password'));
    }

    public function test_production_is_read_only_by_default(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), [
            'name' => 'prod', 'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'shop',
            'username' => 'app', 'environment' => 'production',
        ])->assertRedirect(route('home'));

        $connection = DatabaseConnection::query()->sole();
        $this->assertSame(ConnectionEnvironment::Production, $connection->environment);
        $this->assertTrue($connection->read_only);
    }

    public function test_stores_sqlite_file_in_allowed_folder(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), [
            'name' => 'notizen', 'driver' => 'sqlite', 'sqlite_path' => $this->folder.'/notizen.sqlite',
            'environment' => 'local', 'host' => 'ignoriert',
        ])->assertRedirect(route('home'));

        $connection = DatabaseConnection::query()->sole();
        $this->assertSame($this->folder.'/notizen.sqlite', $connection->sqlite_path);
        $this->assertNull($connection->host);
        $this->assertNotNull($connection->server_version);
    }

    public function test_rejects_host_outside_allowed_list(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), [
            'name' => 'fremd', 'driver' => 'mysql', 'host' => '10.255.255.1', 'username' => 'app', 'environment' => 'local',
        ])->assertSessionHasErrors(['host' => 'Dieser Host ist in dieser Installation nicht erlaubt.']);

        $this->assertDatabaseCount('connections', 0);
    }

    public function test_rejects_sqlite_path_outside_allowed_folders(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), [
            'name' => 'app', 'driver' => 'sqlite', 'sqlite_path' => $this->folder.'/../../../../database/database.sqlite',
            'environment' => 'local',
        ])->assertSessionHasErrors('sqlite_path');

        $this->assertDatabaseCount('connections', 0);
    }

    public function test_requires_fields_per_driver(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), ['driver' => 'pgsql'])
            ->assertSessionHasErrors(['name', 'host', 'username', 'database', 'environment']);
    }

    public function test_edit_page_shows_whether_password_is_stored_but_never_the_password(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($user, 'owner')->create(['password' => 'Geheim-Bearbeiten-4']);

        $response = $this->actingAs($user)->get(route('connections.edit', $connection));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('connections/Form')
            ->where('connection.hasPassword', true)
            ->missing('connection.password'));
        $this->assertStringNotContainsString('Geheim-Bearbeiten-4', (string) $response->getContent());
    }

    public function test_update_with_empty_password_keeps_stored_password(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($user, 'owner')->create(['password' => 'Geheim-Bleibt-5']);

        $this->actingAs($user)->put(route('connections.update', $connection), [
            'name' => 'umbenannt', 'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '1', 'username' => 'app',
            'password' => '', 'store_password' => true, 'environment' => 'local',
        ])->assertRedirect(route('home'));

        $connection->refresh();
        $this->assertSame('umbenannt', $connection->name);
        $this->assertSame('Geheim-Bleibt-5', $connection->password);
    }

    public function test_returns_404_for_connection_of_other_account(): void
    {
        $foreign = DatabaseConnection::factory()->create();

        $this->actingAs(User::factory()->create());

        $this->get(route('connections.edit', $foreign))->assertNotFound();
        $this->put(route('connections.update', $foreign), ['name' => 'x'])->assertNotFound();
        $this->delete(route('connections.destroy', $foreign))->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_member_with_read_permission_cannot_edit_shared_connection(): void
    {
        $user = User::factory()->create();
        $shared = DatabaseConnection::factory()->create(['shared' => true]);
        $shared->members()->attach($user, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($user)->get(route('connections.edit', $shared))->assertForbidden();
        $this->actingAs($user)->delete(route('connections.destroy', $shared))->assertForbidden();
    }

    public function test_removes_own_connection(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($user, 'owner')->create();

        $this->actingAs($user)->delete(route('connections.destroy', $connection))->assertRedirect(route('home'));

        $this->assertModelMissing($connection);
        $this->assertDatabaseHas('audit_log', ['action' => 'connection.deleted', 'target' => $connection->id]);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('connections.create'))->assertRedirect(route('login'));
    }
}
