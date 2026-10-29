<?php

namespace Tests\Feature\Http\Requests\Workspace\Schema;

use App\Enums\ConnectionPermission;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rechte jeder Strukturänderung (S-02 bis S-05): nur mit dem Recht «schreiben» (sonst 403), nie auf einer
 * schreibgeschützten Verbindung (403, auch für den Besitzer), fremde Verbindung 404, auf Produktion erst mit dem
 * eingetippten Namen der Verbindung. Jede abgewiesene Anfrage lässt die Datenbank und das Protokoll unberührt.
 */
class SchemaChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/struktur-rechte-'.getmypid());
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
     * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, mixed>}>
     */
    public static function changeEndpoints(): array
    {
        $table = ['name' => 'neu', 'columns' => [['name' => 'id', 'type' => 'integer', 'nullable' => false]]];
        $alter = ['columns' => [['original' => 'body', 'name' => 'body', 'type' => 'text', 'drop' => true]]];
        $object = ['schema' => 'main', 'object' => 'notes'];

        return [
            'Tabelle anlegen: Vorschau' => ['workspace.table.preview', ['schema' => 'main'], $table],
            'Tabelle anlegen' => ['workspace.table.store', ['schema' => 'main'], $table],
            'Tabelle ändern: Vorschau' => ['workspace.object.alter.preview', $object, $alter],
            'Tabelle ändern' => ['workspace.object.alter.apply', $object, [...$alter, 'confirm_name' => 'notes']],
            'Tabelle leeren: Vorschau' => ['workspace.object.actions.preview', $object, ['operation' => 'truncate']],
            'Tabelle löschen' => ['workspace.object.actions.apply', $object, ['operation' => 'drop', 'confirm_name' => 'notes']],
            'Datei anlegen: Vorschau' => ['workspace.schemas.preview', [], ['operation' => 'create', 'folder' => '', 'file' => 'neu.sqlite']],
            'Datei anlegen' => ['workspace.schemas.apply', [], ['operation' => 'create', 'folder' => '', 'file' => 'neu.sqlite']],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function pages(): array
    {
        return [
            'Tabelle anlegen' => ['workspace.table.create', ['schema' => 'main']],
            'Tabelle ändern' => ['workspace.object.alter', ['schema' => 'main', 'object' => 'notes']],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('changeEndpoints')]
    public function test_member_with_read_permission_gets_403(string $route, array $parameters, array $payload): void
    {
        $connection = $this->sqliteConnection(owner: User::factory()->create(), attributes: ['shared' => true]);
        $connection->members()->attach($this->user, ['permission' => ConnectionPermission::Read->value]);

        $this->actingAs($this->user)->postJson(route($route, [$connection, ...$parameters]), $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Dafür braucht es das Recht «schreiben» an dieser Verbindung.');

        $this->assertUntouched($connection);
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('changeEndpoints')]
    public function test_read_only_connection_gets_403_even_for_its_owner(string $route, array $parameters, array $payload): void
    {
        $connection = $this->sqliteConnection(attributes: ['read_only' => true]);

        $this->actingAs($this->user)->postJson(route($route, [$connection, ...$parameters]), $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Diese Verbindung ist schreibgeschützt — Strukturänderungen sind gesperrt.');

        $this->assertUntouched($connection);
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('changeEndpoints')]
    public function test_connection_of_other_account_is_404(string $route, array $parameters, array $payload): void
    {
        $foreign = $this->sqliteConnection(owner: User::factory()->create());

        $this->actingAs($this->user)->postJson(route($route, [$foreign, ...$parameters]), $payload)->assertNotFound();

        $this->assertUntouched($foreign);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    #[DataProvider('pages')]
    public function test_editing_pages_are_forbidden_without_write_permission_or_on_read_only(string $route, array $parameters): void
    {
        $shared = $this->sqliteConnection(owner: User::factory()->create(), attributes: ['shared' => true]);
        $shared->members()->attach($this->user, ['permission' => ConnectionPermission::Read->value]);
        $readOnly = $this->sqliteConnection(attributes: ['read_only' => true]);
        $this->actingAs($this->user);

        $this->get(route($route, [$shared, ...$parameters]))->assertForbidden();
        $this->get(route($route, [$readOnly, ...$parameters]))->assertForbidden();
        $this->get(route($route, [$this->sqliteConnection(owner: User::factory()->create()), ...$parameters]))->assertNotFound();
    }

    public function test_member_with_write_permission_may_preview(): void
    {
        $connection = $this->sqliteConnection(owner: User::factory()->create(), attributes: ['shared' => true]);
        $connection->members()->attach($this->user, ['permission' => ConnectionPermission::Write->value]);

        $this->actingAs($this->user)->postJson(route('workspace.object.actions.preview', [$connection, 'main', 'notes']), ['operation' => 'truncate'])
            ->assertOk()
            ->assertJsonPath('statements.0.sql', 'DELETE FROM "notes"')
            ->assertJsonPath('confirmName', 'notes');
    }

    public function test_production_requires_the_connection_name_before_running(): void
    {
        $connection = $this->sqliteConnection(attributes: ['name' => 'shop', 'environment' => 'production', 'read_only' => false]);
        $payload = ['operation' => 'rename', 'to' => 'notizen'];
        $this->actingAs($this->user)->from(route('workspace.object.structure', [$connection, 'main', 'notes']));

        $this->post(route('workspace.object.actions.apply', [$connection, 'main', 'notes']), $payload)
            ->assertSessionHasErrors(['confirmation' => 'Geben Sie zur Bestätigung den Namen der Verbindung ein.']);
        $this->post(route('workspace.object.actions.apply', [$connection, 'main', 'notes']), [...$payload, 'confirmation' => 'Shop'])
            ->assertSessionHasErrors(['confirmation' => 'Der Name stimmt nicht mit dem Namen der Verbindung überein.']);
        $this->assertUntouched($connection);

        $this->post(route('workspace.object.actions.apply', [$connection, 'main', 'notes']), [...$payload, 'confirmation' => 'shop'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.object.structure', [$connection, 'main', 'notizen']));
        $this->assertSame(['notizen'], $this->tablesOf($connection));
        $this->assertSame(1, AuditEntry::query()->where('action', 'schema.rename')->count());
    }

    public function test_preview_on_production_needs_no_name(): void
    {
        $connection = $this->sqliteConnection(attributes: ['name' => 'shop', 'environment' => 'production', 'read_only' => false]);

        $this->actingAs($this->user)->postJson(route('workspace.object.actions.preview', [$connection, 'main', 'notes']), ['operation' => 'rename', 'to' => 'notizen'])
            ->assertOk()
            ->assertJsonPath('statements.0.sql', 'ALTER TABLE "notes" RENAME TO "notizen"');
    }

    private function assertUntouched(DatabaseConnection $connection): void
    {
        $this->assertSame(['notes'], $this->tablesOf($connection));
        $this->assertSame(2, (int) (new PDO('sqlite:'.$connection->sqlite_path))->query('select count(*) from notes')->fetchColumn());
        $this->assertFileDoesNotExist($this->folder.'/neu.sqlite');
        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * @return list<string>
     */
    private function tablesOf(DatabaseConnection $connection): array
    {
        return (new PDO('sqlite:'.$connection->sqlite_path))->query("select name from sqlite_master where type = 'table' order by name")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sqliteConnection(?User $owner = null, array $attributes = []): DatabaseConnection
    {
        $file = $this->folder.'/rechte-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table notes (id integer primary key, body text)');
        $pdo->exec("insert into notes (body) values ('eins'), ('zwei')");

        return DatabaseConnection::factory()->for($owner ?? $this->user, 'owner')->sqlite($file)->create($attributes);
    }
}
