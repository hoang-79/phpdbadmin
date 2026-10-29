<?php

namespace Tests\Feature\Http\Controllers\Workspace\Schema;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Tabelle anlegen über die Oberfläche (S-02): Formular mit Typen je Treiber, Vorschau ohne Wirkung, Anlegen mit genau
 * einem Eintrag im Protokoll (Werte maskiert) — und Namen, die Laravels Grammatik zerlegen würde, werden vor jeder
 * Anweisung abgewiesen.
 */
#[Group('target-databases')]
class TableCreateControllerTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/anlegen-'.getmypid());
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

    public function test_form_offers_the_types_of_the_driver_and_tables_of_the_schema(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->get(route('workspace.table.create', [$connection, 'main']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/TableCreate')
                ->where('workspace.schema', 'main')
                ->where('workspace.connection.canWrite', true)
                ->where('editor.tables', ['notes'])
                ->where('editor.mode.key', 'rebuild')
                ->where('editor.comments', false)
                ->where('editor.types.0', ['key' => 'string', 'label' => 'varchar', 'params' => 'length', 'autoIncrement' => false, 'textual' => true])
                ->missing('targets')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('targets.notes', ['id', 'body'])));
    }

    public function test_preview_shows_the_statement_and_creates_nothing(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->postJson(route('workspace.table.preview', [$connection, 'main']), $this->draft('orders'))
            ->assertOk()
            ->assertJsonPath('mode', 'rebuild')
            ->assertJsonPath('before', ['PRAGMA foreign_keys = OFF', 'BEGIN'])
            ->assertJsonPath('statements.0.label', 'Tabelle neu')
            ->assertJsonPath('statements.0.sql', 'create table "orders" ("id" integer primary key autoincrement not null, "note_id" integer not null, "status" varchar not null default \'o\'\'ffen\', foreign key("note_id") references "notes"("id") on delete cascade on update no action)');

        $this->assertSame(['notes'], $this->tablesOf($connection));
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[DataProvider('drivers')]
    public function test_store_creates_the_table_records_one_entry_and_opens_its_structure(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $target->statement('create table notes (id '.($driver === DatabaseDriver::Postgres ? 'bigint' : 'integer').' primary key)');
        $connection = $this->savedConnection($settings, $this->user);
        $schema = $this->sampleSchemaName($driver);
        $draft = $this->draft('orders', $driver === DatabaseDriver::Postgres ? 'bigInteger' : 'integer');

        $this->actingAs($this->user)->from(route('workspace.table.create', [$connection, $schema]))
            ->post(route('workspace.table.store', [$connection, $schema]), $draft)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.object.structure', [$connection, $schema, 'orders']))
            ->assertInertiaFlash('toast.message', 'Tabelle orders angelegt.');

        $this->assertContains('orders', $target->getSchemaBuilder()->getTableListing($schema, schemaQualified: false));
        $entry = AuditEntry::query()->sole();
        $this->assertSame(['schema.create', $schema.'.orders', 'ok'], [$entry->action, $entry->target, $entry->context['outcome'] ?? null]);
        $this->assertStringContainsString('default ?', strtolower((string) $entry->sql));
        $this->assertStringNotContainsString('ffen', (string) $entry->sql);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unsafeNames(): array
    {
        return [
            'Punkt im Tabellennamen' => ['shop.orders', 'id', 'name'],
            '« as » im Tabellennamen' => ['orders as o', 'id', 'name'],
            'Anführungszeichen im Spaltennamen' => ['orders', 'id"; drop table notes; --', 'columns.0.name'],
            'Semikolon im Spaltennamen' => ['orders', 'id;x', 'columns.0.name'],
            'nur Stern' => ['orders', '*', 'columns.0.name'],
            'JSON-Pfad' => ['orders', 'daten->x', 'columns.0.name'],
            'zu lang' => [str_repeat('ä', 32), 'id', 'name'],
        ];
    }

    #[DataProvider('unsafeNames')]
    public function test_names_that_would_change_the_sql_are_rejected_before_any_statement(string $table, string $column, string $field): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->postJson(route('workspace.table.store', [$connection, 'main']), [
            'name' => $table,
            'columns' => [['name' => $column, 'type' => 'integer', 'nullable' => false]],
        ])->assertJsonValidationErrors($field);

        $this->assertSame(['notes'], $this->tablesOf($connection));
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_custom_type_must_look_like_a_type(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->postJson(route('workspace.table.preview', [$connection, 'main']), [
            'name' => 'orders',
            'columns' => [['name' => 'x', 'type' => 'custom', 'custom' => "text default 'a'", 'nullable' => true]],
        ])->assertJsonValidationErrors(['columns.0.custom' => 'Der eigene Typ muss wie ein Typ aussehen, etwa «numeric(12,4)» oder «integer[]» — ohne Anführungszeichen.']);
    }

    public function test_existing_name_is_rejected(): void
    {
        $connection = $this->sqliteConnection();

        $this->actingAs($this->user)->postJson(route('workspace.table.preview', [$connection, 'main']), $this->draft('Notes'))
            ->assertJsonValidationErrors(['name' => 'Eine Tabelle oder Sicht mit diesem Namen gibt es schon.']);
    }

    /**
     * Entwurf: id mit Auto-Increment, Verweis auf notes mit ON DELETE CASCADE, Text mit Vorgabe (mit Anführungszeichen).
     *
     * @return array<string, mixed>
     */
    private function draft(string $name, string $integer = 'integer'): array
    {
        $none = ['kind' => 'none', 'value' => null];

        return [
            'name' => $name,
            'columns' => [
                ['name' => 'id', 'type' => $integer, 'nullable' => false, 'autoIncrement' => true, 'default' => $none],
                ['name' => 'note_id', 'type' => $integer, 'nullable' => false, 'default' => $none],
                ['name' => 'status', 'type' => 'string', 'length' => 20, 'nullable' => false, 'default' => ['kind' => 'value', 'value' => "o'ffen"]],
            ],
            'foreignKeys' => [['columns' => ['note_id'], 'table' => 'notes', 'references' => ['id'], 'onDelete' => 'cascade', 'onUpdate' => 'no action']],
        ];
    }

    /**
     * @return list<string>
     */
    private function tablesOf(DatabaseConnection $connection): array
    {
        return (new PDO('sqlite:'.$connection->sqlite_path))->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function sqliteConnection(): DatabaseConnection
    {
        $file = $this->folder.'/anlegen-'.uniqid().'.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table notes (id integer primary key, body text)');

        return DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create();
    }
}
