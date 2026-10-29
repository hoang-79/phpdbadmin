<?php

namespace Tests\Feature\Http\Controllers\Connections;

use App\Connections\ConnectionSettings;
use App\Connections\HerdDiscovery;
use App\Enums\ConnectionEnvironment;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakeHerd;
use Tests\TestCase;

/**
 * Verbindungen anlegen, bearbeiten, entfernen (V-01, V-03, V-05) und sehen (V-09, T-16).
 */
class ConnectionControllerTest extends TestCase
{
    use FakeHerd;
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

    public function test_overview_shows_stored_account_warnings_in_the_language_of_the_account(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        DatabaseConnection::factory()->for($user, 'owner')->create(['account_warnings' => [[
            'key' => 'superuser',
            'title' => 'Dieses Konto ist Superuser.',
            'recommendation' => 'Empfehlung: ein eigenes Konto ohne Superuser-Recht — für Produktion nur mit Leserechten.',
        ]]]);

        $this->actingAs($user)->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.0.accountWarnings.0.title', 'This account is a superuser.')
                ->where('connections.0.accountWarnings.0.recommendation', 'Recommendation: a separate account without superuser privileges — for production, with read privileges only.'));
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
        $this->assertDatabaseHas('audit_log', ['action' => 'connection.created', 'connection_id' => $connection->id]);
    }

    public function test_does_not_store_password_when_asked_not_to(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), [
            'name' => 'shop', 'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '1', 'username' => 'app',
            'password' => 'Geheim-Nicht-3', 'store_password' => false, 'environment' => 'local', 'all_databases' => true,
        ])->assertRedirect(route('home'));

        $this->assertDatabaseCount('connections', 1);
        $this->assertNull(DB::table('connections')->value('password'));
    }

    public function test_empty_password_is_stored_as_no_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('connections.store'), [
            'name' => 'herd', 'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '1', 'username' => 'root',
            'password' => '', 'store_password' => true, 'environment' => 'local', 'all_databases' => true,
        ])->assertRedirect(route('home'));

        $this->assertNull(DB::table('connections')->value('password'));
        $this->get(route('connections.edit', DatabaseConnection::query()->sole()))
            ->assertInertia(fn (Assert $page) => $page->where('connection.hasPassword', false));
    }

    public function test_all_databases_stores_no_database_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('connections.store'), [
            'name' => 'server', 'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '1', 'username' => 'root',
            'database' => 'wird_ignoriert', 'all_databases' => true, 'environment' => 'local',
        ])->assertRedirect(route('home'));

        $connection = DatabaseConnection::query()->sole();
        $this->assertNull($connection->database);
        $this->get(route('connections.edit', $connection))
            ->assertInertia(fn (Assert $page) => $page->where('connection.allDatabases', true));
    }

    public function test_without_all_databases_a_database_name_is_required(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), [
            'name' => 'shop', 'driver' => 'mysql', 'host' => '127.0.0.1', 'username' => 'app', 'environment' => 'local',
            'all_databases' => false, 'database' => '',
        ])->assertSessionHasErrors(['database' => 'Datenbank fehlt.']);

        $this->assertDatabaseCount('connections', 0);
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
            'password' => '', 'store_password' => true, 'environment' => 'local', 'all_databases' => true,
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
        // Die Policy greift vor der Validierung: ein leerer Antrag verrät nichts über die Felder.
        $this->actingAs($user)->put(route('connections.update', $shared), [])->assertForbidden();
        $this->assertModelExists($shared);
    }

    public function test_member_with_manage_permission_updates_shared_connection(): void
    {
        $user = User::factory()->create();
        $shared = DatabaseConnection::factory()->create(['shared' => true]);
        $shared->members()->attach($user, ['permission' => ConnectionPermission::Manage->value]);

        $this->actingAs($user)->put(route('connections.update', $shared), [
            'name' => 'gemeinsam', 'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '1', 'username' => 'app',
            'store_password' => true, 'environment' => 'local', 'all_databases' => true,
        ])->assertRedirect(route('home'));

        $this->assertSame('gemeinsam', $shared->refresh()->name);
    }

    public function test_admin_sees_every_shared_connection_but_no_personal_connection_of_others(): void
    {
        $admin = User::factory()->admin()->create();
        $shared = DatabaseConnection::factory()->create(['shared' => true]);
        $personal = DatabaseConnection::factory()->create();

        $this->actingAs($admin)->get(route('connections.edit', $shared))->assertOk();
        $this->actingAs($admin)->get(route('connections.edit', $personal))->assertNotFound();
    }

    public function test_removes_own_connection(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($user, 'owner')->create();

        $this->actingAs($user)->delete(route('connections.destroy', $connection))->assertRedirect(route('home'));

        $this->assertModelMissing($connection);
        $this->assertDatabaseHas('audit_log', ['action' => 'connection.deleted', 'connection_id' => $connection->id]);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('connections.create'))->assertRedirect(route('login'));
    }

    public function test_stores_ssh_tunnel_with_encrypted_key_and_passphrase(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('connections.store'), [
            ...$this->sshPayload(),
            'ssh_key' => self::PRIVATE_KEY,
            'ssh_passphrase' => 'Geheim-Phrase-7',
        ])->assertRedirect(route('home'));

        $connection = DatabaseConnection::query()->sole();
        $this->assertTrue($connection->usesSsh());
        $this->assertSame(['127.0.0.1', 2222, 'deploy'], [$connection->ssh_host, $connection->ssh_port, $connection->ssh_username]);
        $this->assertSame(self::PRIVATE_KEY, $connection->ssh_key);
        $this->assertSame('Geheim-Phrase-7', $connection->ssh_passphrase);
        $raw = DB::table('connections')->first(['ssh_key', 'ssh_passphrase']);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $raw?->ssh_key);
        $this->assertStringNotContainsString('Geheim-Phrase-7', (string) $raw?->ssh_passphrase);
        $entry = DB::table('audit_log')->where('action', 'connection.created')->sole();
        $this->assertSame(['driver' => 'mariadb', 'ssh' => true], json_decode((string) $entry->context, true));
        $this->assertStringNotContainsString('Geheim-Phrase-7', json_encode($entry, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_edit_page_shows_ssh_settings_but_never_key_or_passphrase(): void
    {
        $user = User::factory()->create();
        $connection = $this->sshConnection($user);

        $response = $this->actingAs($user)->get(route('connections.edit', $connection));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('connection.ssh.enabled', true)
            ->where('connection.ssh.host', '127.0.0.1')
            ->where('connection.ssh.hasKey', true)
            ->where('connection.ssh.hasPassphrase', true)
            ->missing('connection.ssh.key')
            ->missing('connection.ssh.passphrase'));
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $response->getContent());
        $this->assertStringNotContainsString('Geheim-Phrase-8', (string) $response->getContent());
    }

    public function test_update_with_empty_key_keeps_stored_key_and_passphrase(): void
    {
        $user = User::factory()->create();
        $connection = $this->sshConnection($user);

        $this->actingAs($user)->put(route('connections.update', $connection), $this->sshPayload(['ssh_username' => 'neu']))
            ->assertRedirect(route('home'));

        $connection->refresh();
        $this->assertSame('neu', $connection->ssh_username);
        $this->assertSame(self::PRIVATE_KEY, $connection->ssh_key);
        $this->assertSame('Geheim-Phrase-8', $connection->ssh_passphrase);
    }

    public function test_update_with_neither_key_nor_file_keeps_the_stored_key_file(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->for($user, 'owner')->create([
            'ssh_host' => '127.0.0.1', 'ssh_username' => 'deploy', 'ssh_key_path' => '/srv/keys/id_ed25519', 'ssh_passphrase' => 'Geheim-Phrase-10',
        ]);

        $this->actingAs($user)->put(route('connections.update', $connection), $this->sshPayload(['ssh_key' => '', 'ssh_key_path' => '']))
            ->assertRedirect(route('home'));

        $connection->refresh();
        $this->assertSame('/srv/keys/id_ed25519', $connection->ssh_key_path);
        $this->assertSame('Geheim-Phrase-10', $connection->ssh_passphrase);
    }

    /**
     * N-02: Schlüsseldateien auf dem Server gehören der Installation — ein Mitglied kann sie weder eintragen noch über
     * eine eigene Verbindung benutzen.
     */
    public function test_a_member_cannot_use_a_key_file_from_the_server(): void
    {
        $folder = storage_path('framework/testing/schluessel-'.getmypid());
        @mkdir($folder, 0777, true);
        file_put_contents($folder.'/id_admin', self::PRIVATE_KEY);
        config(['phpdbadmin.ssh.key_paths' => [$folder]]);
        $member = User::factory()->create();

        $this->actingAs($member)->post(route('connections.store'), $this->sshPayload(['ssh_key_path' => $folder.'/id_admin']))
            ->assertSessionHasErrors(['ssh_key_path' => 'Schlüsseldateien auf dem Server dürfen nur Administratoren verwenden. Fügen Sie den Schlüssel ein.']);
        $this->assertSame(0, DatabaseConnection::query()->count());

        // Auch eine Verbindung, die den Pfad schon trägt, benutzt ihn nur, wenn sie einer Administratorin gehört.
        $foreign = DatabaseConnection::factory()->for($member, 'owner')->create(['ssh_host' => '127.0.0.1', 'ssh_username' => 'deploy', 'ssh_key_path' => $folder.'/id_admin']);
        $this->assertNull(ConnectionSettings::fromConnection($foreign)->ssh?->keyPath);
        $own = DatabaseConnection::factory()->for(User::factory()->admin()->create(), 'owner')->create(['ssh_host' => '127.0.0.1', 'ssh_username' => 'deploy', 'ssh_key_path' => $folder.'/id_admin']);
        $this->assertSame($folder.'/id_admin', ConnectionSettings::fromConnection($own)->ssh?->keyPath);

        exec('rm -rf '.escapeshellarg($folder));
    }

    public function test_turning_ssh_off_forgets_key_and_passphrase(): void
    {
        $user = User::factory()->create();
        $connection = $this->sshConnection($user);

        $this->actingAs($user)->put(route('connections.update', $connection), $this->sshPayload(['ssh' => false]))
            ->assertRedirect(route('home'));

        $connection->refresh();
        $this->assertFalse($connection->usesSsh());
        $this->assertNull($connection->ssh_key);
        $this->assertNull($connection->ssh_passphrase);
    }

    public function test_new_ssh_connection_needs_a_key(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), $this->sshPayload())
            ->assertSessionHasErrors(['ssh_key' => __('validation.required', ['attribute' => __('Privater Schlüssel')])]);
    }

    public function test_rejects_ssh_host_outside_allowed_list_and_unsafe_values(): void
    {
        $this->actingAs(User::factory()->create())->post(route('connections.store'), $this->sshPayload([
            'ssh_host' => '10.9.8.7',
            'ssh_username' => '-oProxyCommand=boom',
            'ssh_key' => 'kein Schlüssel',
        ]))->assertSessionHasErrors([
            'ssh_host' => __('Dieser Host ist in dieser Installation nicht erlaubt.'),
            'ssh_username',
            'ssh_key' => __('Das sieht nicht nach einem privaten Schlüssel aus (-----BEGIN … PRIVATE KEY-----).'),
        ]);

        $this->assertSame(0, DatabaseConnection::query()->count());
    }

    private const string PRIVATE_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----";

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sshPayload(array $overrides = []): array
    {
        return [
            'name' => 'hinter ssh', 'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '3306', 'username' => 'app',
            'password' => '', 'store_password' => true, 'environment' => 'local', 'all_databases' => true,
            'ssh' => true, 'ssh_host' => '127.0.0.1', 'ssh_port' => '2222', 'ssh_username' => 'deploy',
            ...$overrides,
        ];
    }

    private function sshConnection(User $owner): DatabaseConnection
    {
        return DatabaseConnection::factory()->for($owner, 'owner')->create([
            'ssh_host' => '127.0.0.1', 'ssh_port' => 2222, 'ssh_username' => 'deploy',
            'ssh_key' => self::PRIVATE_KEY, 'ssh_passphrase' => 'Geheim-Phrase-8',
        ]);
    }

    public function test_admin_gets_herd_suggestions_without_passwords(): void
    {
        $this->enableFakeHerd();

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('connections.create'));

        $response->assertInertia(fn (Assert $page) => $page
            ->missing('herd')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('herd', fn ($suggestions): bool => collect($suggestions)->contains(fn (array $suggestion): bool => $suggestion['name'] === 'shop' && $suggestion['hasPassword'] === true)
                    && ! str_contains((string) json_encode($suggestions), self::HERD_SHOP_PASSWORD))
                ->etc()));
        $this->assertStringNotContainsString(self::HERD_SHOP_PASSWORD, (string) $response->getContent());
    }

    public function test_member_gets_no_herd_suggestions(): void
    {
        $this->enableFakeHerd();

        $this->actingAs(User::factory()->create())->get(route('connections.create'))
            ->assertInertia(fn (Assert $page) => $page->where('herd', null));
    }

    public function test_herd_suggestion_brings_the_password_from_the_site_only_for_the_same_server(): void
    {
        $this->enableFakeHerd();
        $admin = User::factory()->admin()->create();
        $shop = collect(app(HerdDiscovery::class)->suggestions())->firstWhere('name', 'shop');
        $this->assertNotNull($shop);
        $payload = ['name' => 'shop', 'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3307', 'username' => 'shop', 'database' => 'shop',
            'password' => '', 'store_password' => true, 'environment' => 'local', 'herd_source' => $shop->id];

        $this->actingAs($admin)->post(route('connections.store'), $payload)->assertRedirect(route('home'));
        $this->assertSame(self::HERD_SHOP_PASSWORD, DatabaseConnection::query()->sole()->password);

        $this->actingAs($admin)->post(route('connections.store'), [...$payload, 'name' => 'anderer Port', 'port' => '3306'])->assertRedirect(route('home'));
        $this->assertNull(DatabaseConnection::query()->where('name', 'anderer Port')->sole()->password);
    }

    private function enableFakeHerd(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($listener);
        $name = (string) stream_socket_get_name($listener, false);
        fclose($listener);

        config([
            'phpdbadmin.herd.enabled' => true,
            'phpdbadmin.herd.path' => $this->fakeHerd($this->folder.'/herd', (int) substr($name, (int) strrpos($name, ':') + 1), 1),
        ]);
    }
}
