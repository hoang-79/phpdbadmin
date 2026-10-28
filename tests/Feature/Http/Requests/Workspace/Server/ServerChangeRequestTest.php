<?php

namespace Tests\Feature\Http\Requests\Workspace\Server;

use App\Enums\ConnectionPermission;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rechte jeder ändernden Aktion der Server-Verwaltung (W-02, W-04, W-05): ohne «verwalten» 403, schreibgeschützt 403,
 * fremde Verbindung 404, auf Produktion erst mit dem eingetippten Namen. Jede abgewiesene Anfrage hinterlässt nichts
 * im Protokoll.
 */
class ServerChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/server-rechte-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function changeEndpoints(): array
    {
        $account = ['action' => 'create', 'name' => 'phpdbadmin_w04_x', 'host' => '%', 'password' => 'Geheim-W04-1234'];
        $maintenance = ['operation' => 'analyze', 'schema' => 'main'];

        return [
            'Prozess beenden' => ['workspace.server.processes.end', ['process' => 42], ['mode' => 'session']],
            'Konto: Vorschau' => ['workspace.server.accounts.preview', [], $account],
            'Konto: ausführen' => ['workspace.server.accounts.apply', [], $account],
            'Wartung: Vorschau' => ['workspace.server.maintenance.preview', [], $maintenance],
            'Wartung: ausführen' => ['workspace.server.maintenance.run', [], $maintenance],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('changeEndpoints')]
    public function test_member_with_write_but_not_manage_permission_gets_403(string $route, array $parameters, array $payload): void
    {
        $connection = $this->sqliteConnection(owner: User::factory()->create(), attributes: ['shared' => true]);
        $connection->members()->attach($this->user, ['permission' => ConnectionPermission::Write->value]);

        $this->actingAs($this->user)->postJson(route($route, [$connection, ...$parameters]), $payload)->assertForbidden();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('changeEndpoints')]
    public function test_read_only_connection_gets_403_even_for_its_owner(string $route, array $parameters, array $payload): void
    {
        $connection = $this->sqliteConnection(attributes: ['read_only' => true]);

        $this->actingAs($this->user)->postJson(route($route, [$connection, ...$parameters]), $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Diese Verbindung ist schreibgeschützt — Änderungen sind gesperrt.');

        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('changeEndpoints')]
    public function test_connection_of_other_account_is_404(string $route, array $parameters, array $payload): void
    {
        $foreign = $this->sqliteConnection(owner: User::factory()->create());

        $this->actingAs($this->user)->postJson(route($route, [$foreign, ...$parameters]), $payload)->assertNotFound();
    }

    public function test_member_with_manage_permission_may_change(): void
    {
        $connection = $this->sqliteConnection(owner: User::factory()->create(), attributes: ['shared' => true]);
        $connection->members()->attach($this->user, ['permission' => ConnectionPermission::Manage->value]);

        $this->actingAs($this->user)->postJson(route('workspace.server.maintenance.preview', $connection), ['operation' => 'analyze', 'schema' => 'main'])
            ->assertOk()
            ->assertJsonPath('statements', ['ANALYZE "main"']);
    }

    public function test_production_requires_the_connection_name_before_running(): void
    {
        $connection = $this->sqliteConnection(attributes: ['name' => 'shop', 'environment' => 'production', 'read_only' => false]);
        $payload = ['operation' => 'analyze', 'schema' => 'main'];
        $this->actingAs($this->user)->from(route('workspace.server.maintenance', $connection));

        $this->post(route('workspace.server.maintenance.run', $connection), $payload)
            ->assertSessionHasErrors(['confirmation' => 'Geben Sie zur Bestätigung den Namen der Verbindung ein.']);
        $this->post(route('workspace.server.maintenance.run', $connection), [...$payload, 'confirmation' => 'Shop'])
            ->assertSessionHasErrors(['confirmation' => 'Der Name stimmt nicht mit dem Namen der Verbindung überein.']);
        $this->assertSame(0, AuditEntry::query()->count());

        $this->post(route('workspace.server.maintenance.run', $connection), [...$payload, 'confirmation' => 'shop'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.server.maintenance', $connection));
        $this->assertSame(1, AuditEntry::query()->where('action', 'server.maintenance')->count());
    }

    public function test_preview_on_production_needs_no_name(): void
    {
        $connection = $this->sqliteConnection(attributes: ['name' => 'shop', 'environment' => 'production', 'read_only' => false]);

        $this->actingAs($this->user)->postJson(route('workspace.server.maintenance.preview', $connection), ['operation' => 'analyze', 'schema' => 'main'])
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sqliteConnection(?User $owner = null, array $attributes = []): DatabaseConnection
    {
        $file = $this->folder.'/rechte-'.uniqid().'.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table notes (id integer primary key)');

        return DatabaseConnection::factory()->for($owner ?? $this->user, 'owner')->sqlite($file)->create($attributes);
    }
}
