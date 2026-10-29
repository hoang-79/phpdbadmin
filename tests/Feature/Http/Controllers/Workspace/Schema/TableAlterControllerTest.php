<?php

namespace Tests\Feature\Http\Controllers\Workspace\Schema;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Tabelle ändern über die Oberfläche (S-03, N-03): die Seite zeigt den Stand als Entwurf; die Vorschau ist genau das,
 * was beim Anwenden läuft; Spalten löschen geht nur mit dem Namen der Tabelle; scheitert MySQL mitten in der Folge,
 * steht danach da, was gelaufen ist.
 */
#[Group('target-databases')]
class TableAlterControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/aendern-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_page_shows_the_table_as_draft(): void
    {
        [$connection] = $this->parentWithChildren(DatabaseDriver::Sqlite);

        $this->actingAs($this->user)->get(route('workspace.object.alter', [$connection, 'main', 'parents']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/TableAlter')
                ->where('structure.object.name', 'parents')
                ->where('draft.columns.1', [
                    'name' => 'name', 'type' => 'string', 'custom' => null, 'length' => null, 'precision' => null, 'scale' => null,
                    'values' => [], 'nullable' => false, 'default' => ['kind' => 'value', 'value' => 'ohne'], 'autoIncrement' => false,
                    'comment' => null, 'original' => 'name',
                ])
                ->where('draft.indexes.0', ['original' => 'parents_code_unique', 'name' => 'parents_code_unique', 'columns' => ['code'], 'unique' => true, 'primary' => false])
                ->where('editor.mode.key', 'rebuild'));
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function drivers(): array
    {
        return [...self::serverDrivers(), 'SQLite' => [DatabaseDriver::Sqlite]];
    }

    /**
     * Der Entwurf, wie die Seite ihn liefert, geht unverändert zurück — mit dem Primärschlüssel, den MySQL, MariaDB und
     * PostgreSQL als Index nennen — plus eine neue Spalte. Im Browser lehnte die Vorschau das ab («Diesen Index gibt es
     * nicht»), weil der Primärschlüssel als Änderung galt.
     */
    #[DataProvider('drivers')]
    public function test_the_unchanged_draft_of_the_page_plus_a_new_column_previews(DatabaseDriver $driver): void
    {
        [$connection, $schema] = $this->parentWithChildren($driver);
        $this->actingAs($this->user);
        $draft = $this->get(route('workspace.object.alter', [$connection, $schema, 'parents']))->inertiaProps('draft');

        $payload = [
            'columns' => [...array_map(fn (array $column): array => [...$column, 'changed' => false], $draft['columns']), ['name' => 'notiz', 'type' => 'string', 'length' => 255, 'nullable' => true]],
            'indexes' => array_map(fn (array $index): array => [...$index, 'drop' => false], $draft['indexes']),
            'foreignKeys' => array_map(fn (array $foreignKey): array => [...$foreignKey, 'drop' => false], $draft['foreignKeys']),
        ];

        $statements = $this->postJson(route('workspace.object.alter.preview', [$connection, $schema, 'parents']), $payload)
            ->assertOk()
            ->json('statements');

        $this->assertStringContainsString('notiz', implode("\n", array_column($statements, 'sql')));
    }

    public function test_views_have_no_alter_page(): void
    {
        [$connection, , $target] = $this->parentWithChildren(DatabaseDriver::Sqlite);
        $target->statement('create view parent_names as select name from parents');

        $this->actingAs($this->user)->get(route('workspace.object.alter', [$connection, 'main', 'parent_names']))->assertNotFound();
    }

    public function test_preview_through_the_endpoint_is_exactly_what_applying_runs(): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren(DatabaseDriver::Sqlite);
        $payload = ['columns' => [
            ['original' => 'name', 'name' => 'title', 'type' => 'string', 'length' => 80, 'nullable' => false, 'changed' => true, 'default' => ['kind' => 'none']],
            ['name' => 'rank', 'type' => 'integer', 'nullable' => true],
        ]];
        $this->actingAs($this->user);
        $preview = $this->postJson(route('workspace.object.alter.preview', [$connection, $schema, 'parents']), $payload)
            ->assertOk()
            ->assertJsonPath('mode', 'rebuild')
            ->assertJsonPath('after', ['PRAGMA foreign_key_check', 'COMMIT', 'PRAGMA foreign_keys = ON'])
            ->json('statements');
        $executed = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$executed): void {
            if (str_starts_with((string) $query->connectionName, 'target_') && preg_match('/^\s*(select|pragma|set)\b/i', $query->sql) !== 1) {
                $executed[] = $query->sql;
            }
        });

        $this->from(route('workspace.object.alter', [$connection, $schema, 'parents']))
            ->post(route('workspace.object.alter.apply', [$connection, $schema, 'parents']), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.object.structure', [$connection, $schema, 'parents']));

        $this->assertSame(array_column($preview, 'sql'), $executed);
        $this->assertSame(['id', 'title', 'code', 'rank'], array_column($target->getSchemaBuilder()->getColumns('parents'), 'name'));
        $this->assertSame(2, $target->table('children')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'schema.alter')->count());
    }

    public function test_dropping_a_column_runs_only_with_the_table_name(): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren(DatabaseDriver::Sqlite);
        $payload = [
            'columns' => [['original' => 'code', 'name' => 'code', 'type' => 'string', 'drop' => true]],
            'indexes' => [['original' => 'parents_code_unique', 'drop' => true, 'columns' => ['code']]],
        ];
        $this->actingAs($this->user)->from(route('workspace.object.alter', [$connection, $schema, 'parents']));

        $this->postJson(route('workspace.object.alter.preview', [$connection, $schema, 'parents']), $payload)
            ->assertOk()
            ->assertJsonPath('confirmName', 'parents')
            ->assertJsonPath('losses', ['Die Spalte code wird mit allen Werten gelöscht (≈ 2 Zeilen).']);
        $this->post(route('workspace.object.alter.apply', [$connection, $schema, 'parents']), $payload)
            ->assertSessionHasErrors(['confirm_name' => 'Geben Sie zur Bestätigung den Namen «parents» ein.']);
        $this->post(route('workspace.object.alter.apply', [$connection, $schema, 'parents']), [...$payload, 'confirm_name' => 'Parents'])
            ->assertSessionHasErrors('confirm_name');
        $this->assertContains('code', array_column($target->getSchemaBuilder()->getColumns('parents'), 'name'));
        $this->assertSame(0, AuditEntry::query()->count());

        $this->post(route('workspace.object.alter.apply', [$connection, $schema, 'parents']), [...$payload, 'confirm_name' => 'parents'])
            ->assertSessionHasNoErrors();

        $this->assertNotContains('code', array_column($target->getSchemaBuilder()->getColumns('parents'), 'name'));
        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_dropping_a_column_needs_its_indexes_dropped_too(): void
    {
        [$connection, $schema] = $this->parentWithChildren(DatabaseDriver::Sqlite);

        $this->actingAs($this->user)->postJson(route('workspace.object.alter.preview', [$connection, $schema, 'parents']), [
            'columns' => [['original' => 'code', 'name' => 'code', 'type' => 'string', 'drop' => true]],
        ])->assertJsonValidationErrors(['indexes' => 'Der Index parents_code_unique nutzt die Spalte code, die wegfällt — löschen Sie ihn mit.']);
    }

    public function test_failure_on_mysql_reports_which_statements_ran(): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren(DatabaseDriver::MySql);
        $target->table('parents')->update(['name' => 'gleich']);
        $payload = [
            'columns' => [['name' => 'extra', 'type' => 'string', 'length' => 20, 'nullable' => true]],
            'indexes' => [['columns' => ['name'], 'unique' => true]],
        ];

        $this->actingAs($this->user)->from(route('workspace.object.alter', [$connection, $schema, 'parents']))
            ->post(route('workspace.object.alter.apply', [$connection, $schema, 'parents']), $payload)
            ->assertRedirect(route('workspace.object.alter', [$connection, $schema, 'parents']))
            ->assertSessionHasErrors('server')
            ->assertInertiaFlash('schemaFailure.applied', 1)
            ->assertInertiaFlash('schemaFailure.failed', 2)
            ->assertInertiaFlash('schemaFailure.rolledBack', false);

        $this->assertContains('extra', array_column($target->getSchemaBuilder()->getColumns($schema.'.parents'), 'name'));
        $this->assertSame('failed', AuditEntry::query()->sole()->context['outcome'] ?? null);
    }

    public function test_nothing_to_apply_is_rejected(): void
    {
        [$connection, $schema] = $this->parentWithChildren(DatabaseDriver::Sqlite);

        $this->actingAs($this->user)->postJson(route('workspace.object.alter.apply', [$connection, $schema, 'parents']), ['columns' => []])
            ->assertJsonValidationErrors(['draft' => 'Es gibt nichts anzuwenden.']);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * @return array{0: DatabaseConnection, 1: string, 2: Connection}
     */
    private function parentWithChildren(DatabaseDriver $driver): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $target->getSchemaBuilder()->create('parents', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('ohne');
            $table->string('code', 10)->unique();
        });
        $target->getSchemaBuilder()->create('children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('parents')->cascadeOnDelete();
        });
        $target->table('parents')->insert([['name' => 'A', 'code' => 'a'], ['name' => 'B', 'code' => 'b']]);
        $target->table('children')->insert([['parent_id' => 1], ['parent_id' => 2]]);

        return [$this->savedConnection($settings, $this->user), $this->sampleSchemaName($driver), $target];
    }
}
