<?php

namespace Tests\Feature\Http\Controllers\Workspace\Schema;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Tabelle umbenennen, kopieren, leeren und löschen (S-04) je Treiber: Fremdschlüssel der Kinder folgen dem neuen
 * Namen, die Kopie hat dieselbe Struktur und eigene Zähler, Leeren und Löschen laufen nur mit dem Namen der Tabelle —
 * und eine Elterntabelle mit abhängigen Zeilen lässt sich weder leeren noch löschen (Kinder bleiben).
 */
#[Group('target-databases')]
class TableActionControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/aktionen-'.getmypid());
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

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function drivers(): array
    {
        return [...self::serverDrivers(), 'SQLite' => [DatabaseDriver::Sqlite]];
    }

    #[DataProvider('drivers')]
    public function test_rename_lets_the_children_follow_the_new_name(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren($driver);

        $this->apply($connection, $schema, 'parents', ['operation' => 'rename', 'to' => 'eltern'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.object.structure', [$connection, $schema, 'eltern']));

        $builder = $target->getSchemaBuilder();
        $this->assertNotContains('parents', $builder->getTableListing($schema, schemaQualified: false));
        $this->assertSame('eltern', $builder->getForeignKeys($schema.'.children')[0]['foreign_table']);
        $this->assertSame(2, $target->table($schema.'.eltern')->count());
        $this->assertSame(['schema.rename', $schema.'.parents', 'eltern'], [AuditEntry::query()->sole()->action, AuditEntry::query()->sole()->target, AuditEntry::query()->sole()->context['to'] ?? null]);
    }

    #[DataProvider('drivers')]
    public function test_copy_with_data_keeps_structure_rows_and_continues_the_counter(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren($driver);

        $this->apply($connection, $schema, 'parents', ['operation' => 'copy', 'to' => 'parents_kopie', 'with_data' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.object.structure', [$connection, $schema, 'parents_kopie']));

        $builder = $target->getSchemaBuilder();
        $shape = fn (string $table): array => array_map(fn (array $column): array => [$column['name'], $column['type'], $column['nullable']], $builder->getColumns($schema.'.'.$table));
        $this->assertSame($shape('parents'), $shape('parents_kopie'));
        $this->assertContains(['code'], array_column(array_filter($builder->getIndexes($schema.'.parents_kopie'), fn (array $index): bool => $index['unique'] && ! $index['primary']), 'columns'));
        $this->assertSame(['A', 'B'], $target->table($schema.'.parents_kopie')->orderBy('id')->pluck('name')->all());

        $target->table($schema.'.parents_kopie')->insert(['name' => 'C', 'code' => 'c']);
        $this->assertSame(3, (int) $target->table($schema.'.parents_kopie')->where('code', 'c')->value('id'));
        $target->table($schema.'.parents')->insert(['name' => 'D', 'code' => 'd']);
        $this->assertSame(3, (int) $target->table($schema.'.parents')->where('code', 'd')->value('id'));
    }

    public function test_copy_of_the_structure_only_has_no_rows(): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren(DatabaseDriver::Postgres);

        $this->apply($connection, $schema, 'parents', ['operation' => 'copy', 'to' => 'leer', 'with_data' => false])->assertSessionHasNoErrors();

        $this->assertSame(0, $target->table($schema.'.leer')->count());
        $target->table($schema.'.leer')->insert(['name' => 'X', 'code' => 'x']);
        $this->assertSame(1, (int) $target->table($schema.'.leer')->value('id'));
    }

    #[DataProvider('drivers')]
    public function test_truncate_and_drop_run_only_with_the_table_name(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren($driver);

        $this->apply($connection, $schema, 'children', ['operation' => 'truncate'])
            ->assertSessionHasErrors(['confirm_name' => 'Geben Sie zur Bestätigung den Namen «children» ein.']);
        $this->apply($connection, $schema, 'children', ['operation' => 'drop', 'confirm_name' => 'CHILDREN'])
            ->assertSessionHasErrors('confirm_name');
        $this->assertSame(2, $target->table($schema.'.children')->count());
        $this->assertSame(0, AuditEntry::query()->count());

        $this->apply($connection, $schema, 'children', ['operation' => 'truncate', 'confirm_name' => 'children'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.object.structure', [$connection, $schema, 'children']));
        $this->assertSame(0, $target->table($schema.'.children')->count());
        $this->assertSame(2, $target->table($schema.'.parents')->count());

        $this->apply($connection, $schema, 'children', ['operation' => 'drop', 'confirm_name' => 'children'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.schema', [$connection, $schema]));
        $this->assertNotContains('children', $target->getSchemaBuilder()->getTableListing($schema, schemaQualified: false));
        $this->assertSame(['schema.truncate', 'schema.drop'], AuditEntry::query()->orderBy('id')->pluck('action')->all());
    }

    #[DataProvider('drivers')]
    public function test_a_parent_with_dependent_rows_is_neither_emptied_nor_dropped(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren($driver);

        $this->apply($connection, $schema, 'parents', ['operation' => 'drop', 'confirm_name' => 'parents'])
            ->assertSessionHasErrors('server')
            ->assertInertiaFlash('schemaFailure.applied', 0);
        $this->apply($connection, $schema, 'parents', ['operation' => 'truncate', 'confirm_name' => 'parents'])
            ->assertSessionHasErrors('server');

        $this->assertSame(2, $target->table($schema.'.parents')->count());
        $this->assertSame(2, $target->table($schema.'.children')->count());
        $this->assertSame(['failed', 'failed'], AuditEntry::query()->orderBy('id')->get()->map(fn (AuditEntry $entry): mixed => $entry->context['outcome'] ?? null)->all());
    }

    public function test_views_are_not_tables(): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren(DatabaseDriver::Sqlite);
        $target->statement('create view parent_names as select name from parents');

        $this->actingAs($this->user)->postJson(route('workspace.object.actions.preview', [$connection, $schema, 'parent_names']), ['operation' => 'truncate'])
            ->assertJsonValidationErrors(['table' => 'Diese Tabelle gibt es nicht.']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(DatabaseConnection $connection, string $schema, string $table, array $payload): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('workspace.object.structure', [$connection, $schema, $table]))
            ->post(route('workspace.object.actions.apply', [$connection, $schema, $table]), $payload);
    }

    /**
     * Elterntabelle mit zwei Zeilen (Zähler bei 2), Kindtabelle mit zwei Zeilen per ON DELETE CASCADE darauf.
     *
     * @return array{0: DatabaseConnection, 1: string, 2: Connection}
     */
    private function parentWithChildren(DatabaseDriver $driver): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $target->getSchemaBuilder()->create('parents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10)->unique();
        });
        $target->getSchemaBuilder()->create('children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('parents')->cascadeOnDelete();
        });
        $target->table('parents')->insert([['name' => 'A', 'code' => 'a'], ['name' => 'B', 'code' => 'b']]);
        $target->table('children')->insert([['parent_id' => 1], ['parent_id' => 1]]);

        return [$this->savedConnection($settings, $this->user), $this->sampleSchemaName($driver), $target];
    }
}
