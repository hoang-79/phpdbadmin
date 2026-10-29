<?php

namespace Tests\Feature\Services\Schema;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Schema\SchemaChangeFailed;
use App\Services\Schema\SchemaChanges;
use App\Services\Schema\SchemaPlan;
use App\Services\Schema\SchemaPlans;
use App\Services\Schema\TableDraft;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Strukturänderungen je Treiber (S-02, S-03, N-03; Abnahme aus F-5, ADR-003): die Vorschau ist genau das, was läuft;
 * eine angelegte Tabelle ist per Introspektion gleich der Eingabe; beim Ändern einer Elterntabelle mit abhängigen
 * Zeilen bleiben Daten, Indizes, Fremdschlüssel und Kinder; ein Fehler mitten in der Folge lässt MySQL und MariaDB
 * mit den gelaufenen Schritten zurück und PostgreSQL und SQLite unverändert.
 */
#[Group('target-databases')]
class SchemaChangesTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/struktur-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
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
    public function test_created_table_matches_the_draft_and_preview_equals_what_ran(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren($driver);
        $key = match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => 'unsignedBigInteger',
            DatabaseDriver::Postgres => 'bigInteger',
            DatabaseDriver::Sqlite => 'integer',
        };
        $draft = TableDraft::fromArray([
            'name' => 'orders',
            'columns' => [
                $this->column('id', $key, ['autoIncrement' => true]),
                $this->column('parent_id', $key),
                $this->column('status', 'string', ['length' => 20, 'default' => ['kind' => 'value', 'value' => "o'ffen"]]),
                $this->column('total', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => ['kind' => 'value', 'value' => '0']]),
                $this->column('note', 'string', ['length' => 40, 'nullable' => true, 'comment' => 'Notiz']),
                $this->column('created_at', $driver === DatabaseDriver::Postgres ? 'timestamp' : 'dateTime', ['nullable' => true, 'default' => ['kind' => 'expression', 'value' => 'CURRENT_TIMESTAMP']]),
            ],
            'indexes' => [['columns' => ['status'], 'unique' => false], ['columns' => ['note'], 'unique' => true]],
            'foreignKeys' => [['columns' => ['parent_id'], 'table' => 'parents', 'references' => ['id'], 'onDelete' => 'cascade', 'onUpdate' => 'no action']],
        ]);
        $plans = app(SchemaPlans::class);
        $preview = app(SchemaChanges::class)->preview($connection, fn (Connection $read): SchemaPlan => $plans->create($read, $connection, $schema, $draft));

        $executed = $this->recording(fn () => app(SchemaChanges::class)->apply($connection, User::factory()->create(), fn (Connection $write): SchemaPlan => $plans->create($write, $connection, $schema, $draft)));

        $this->assertSame($preview->statements, $executed);
        $builder = $target->getSchemaBuilder();
        $columns = array_column($builder->getColumns($schema.'.orders'), null, 'name');
        $this->assertSame(['id', 'parent_id', 'status', 'total', 'note', 'created_at'], array_keys($columns));
        $this->assertSame([false, false, false, false, true, true], array_values(array_map(fn (array $column): bool => $column['nullable'], $columns)));
        $this->assertTrue($columns['id']['auto_increment']);
        $this->assertSame(match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => ['varchar(20)', 'decimal(10,2)', 'varchar(40)'],
            DatabaseDriver::Postgres => ['character varying(20)', 'numeric(10,2)', 'character varying(40)'],
            DatabaseDriver::Sqlite => ['varchar', 'numeric', 'varchar'],
        }, [$columns['status']['type'], $columns['total']['type'], $columns['note']['type']]);
        $this->assertStringContainsString("o''ffen", (string) $columns['status']['default'] === "o'ffen" ? "o''ffen" : (string) $columns['status']['default']);
        $indexes = array_column($builder->getIndexes($schema.'.orders'), null, 'name');
        $this->assertSame(['id'], array_values(array_filter($indexes, fn (array $index): bool => $index['primary']))[0]['columns']);
        $this->assertSame(['status'], $indexes['orders_status_index']['columns']);
        $this->assertTrue($indexes['orders_note_unique']['unique']);
        $foreignKey = $builder->getForeignKeys($schema.'.orders')[0];
        $this->assertSame([['parent_id'], 'parents', ['id'], 'cascade'], [$foreignKey['columns'], $foreignKey['foreign_table'], $foreignKey['foreign_columns'], strtolower((string) $foreignKey['on_delete'])]);
        $this->assertSame(1, AuditEntry::query()->where('action', 'schema.create')->count());
    }

    #[DataProvider('drivers')]
    public function test_altering_a_parent_keeps_data_indexes_foreign_keys_and_children(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren($driver);
        $draft = TableDraft::fromArray([
            'columns' => [
                $this->existing('id'),
                ['original' => 'name', ...$this->column('name', 'string', ['length' => 200]), 'changed' => true],
                ['original' => 'code', ...$this->column('code', 'string', ['length' => 10])],
                ['original' => 'note', ...$this->column('remark', 'text', ['nullable' => true])],
                ['original' => 'legacy', ...$this->column('legacy', 'string', ['length' => 20, 'nullable' => true]), 'drop' => true],
                $this->column('status', 'string', ['length' => 20, 'default' => ['kind' => 'value', 'value' => 'aktiv']]),
            ],
            'indexes' => [['columns' => ['name'], 'unique' => false]],
        ], 'parents');
        $plans = app(SchemaPlans::class);
        $preview = app(SchemaChanges::class)->preview($connection, fn (Connection $read): SchemaPlan => $plans->alter($read, $connection, $schema, 'parents', $draft));

        $executed = $this->recording(fn () => app(SchemaChanges::class)->apply($connection, User::factory()->create(), fn (Connection $write): SchemaPlan => $plans->alter($write, $connection, $schema, 'parents', $draft), confirmedName: 'parents'));

        $this->assertSame($preview->statements, $executed);
        $this->assertSame('parents', $preview->confirmName);
        $this->assertSame(2, $target->table($schema.'.children')->count());
        $this->assertSame([['Eltern A', 'A', 'erste', 'aktiv'], ['Eltern B', 'B', null, 'aktiv']], array_map(
            fn (object $row): array => [$row->name, $row->code, $row->remark, $row->status],
            $target->table($schema.'.parents')->orderBy('id')->get()->all(),
        ));
        $builder = $target->getSchemaBuilder();
        $this->assertSame(['id', 'name', 'code', 'remark', 'status'], array_column($builder->getColumns($schema.'.parents'), 'name'));
        $indexes = array_column($builder->getIndexes($schema.'.parents'), 'columns', 'name');
        $this->assertSame(['code'], $indexes['parents_code_unique']);
        $this->assertSame(['name'], $indexes['parents_name_index']);
        $children = $builder->getForeignKeys($schema.'.children');
        $this->assertSame([['parent_id'], 'parents', 'cascade'], [$children[0]['columns'], $children[0]['foreign_table'], strtolower((string) $children[0]['on_delete'])]);

        if ($driver === DatabaseDriver::Sqlite) {
            $this->assertSame([], $target->select('PRAGMA foreign_key_check'));
            $this->assertSame(1, (int) $target->scalar('PRAGMA foreign_keys'));
        }
    }

    #[DataProvider('drivers')]
    public function test_failure_in_the_middle_keeps_applied_steps_only_where_ddl_is_not_transactional(DatabaseDriver $driver): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren($driver);
        $target->table($schema.'.parents')->where('code', 'B')->update(['name' => 'Eltern A']);
        $draft = TableDraft::fromArray([
            'columns' => [$this->column('extra', 'string', ['length' => 20, 'nullable' => true])],
            'indexes' => [['columns' => ['name'], 'unique' => true]],
        ], 'parents');
        $plans = app(SchemaPlans::class);

        try {
            app(SchemaChanges::class)->apply($connection, User::factory()->create(), fn (Connection $write): SchemaPlan => $plans->alter($write, $connection, $schema, 'parents', $draft));
            $this->fail('Der eindeutige Index auf doppelte Namen hätte scheitern müssen.');
        } catch (SchemaChangeFailed $failure) {
            $outcome = $failure->toArray();
        }

        $columns = array_column($target->getSchemaBuilder()->getColumns($schema.'.parents'), 'name');
        $transactional = ! in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true);
        $this->assertSame($transactional ? 0 : 1, $outcome['applied']);
        $this->assertSame($transactional, $outcome['rolledBack']);
        $this->assertSame(! $transactional, in_array('extra', $columns, true));
        $this->assertSame(2, $target->table($schema.'.children')->count());
        $entry = AuditEntry::query()->sole();
        $this->assertSame(['schema.alter', 'failed', $outcome['applied']], [$entry->action, $entry->context['outcome'] ?? null, $entry->context['applied'] ?? null]);
    }

    public function test_sqlite_rebuild_keeps_checks_triggers_and_dependent_views(): void
    {
        [$connection, $schema, $target] = $this->parentWithChildren(DatabaseDriver::Sqlite);
        $target->statement('create table audits (id integer primary key, parent_id integer, amount integer not null default 0 constraint audits_amount_check check (amount >= 0))');
        $target->statement('create view parent_names as select id, name from parents');
        $target->unprepared('create trigger parents_audit after insert on parents begin insert into audits (parent_id) values (new.id); end');
        $target->statement('drop table children');
        $target->statement('create table children (id integer primary key autoincrement, parent_id integer not null references parents (id) on delete cascade, weight integer not null check (weight > 0))');
        $target->table('children')->insert([['parent_id' => 1, 'weight' => 1], ['parent_id' => 1, 'weight' => 2]]);
        $draft = TableDraft::fromArray([
            'columns' => [['original' => 'weight', ...$this->column('weight', 'integer'), 'changed' => true, 'nullable' => true]],
        ], 'children');

        app(SchemaChanges::class)->apply($connection, User::factory()->create(), fn (Connection $write): SchemaPlan => app(SchemaPlans::class)->alter($write, $connection, $schema, 'children', $draft));

        $this->assertSame([['weight > 0']], array_map(fn (array $check): array => [$check['definition']], DatabaseDriver::Sqlite->dialect()->checkConstraints($target, 'main', 'children')));
        $this->assertSame(2, $target->table('children')->count());

        app(SchemaChanges::class)->apply($connection, User::factory()->create(), fn (Connection $write): SchemaPlan => app(SchemaPlans::class)->alter($write, $connection, $schema, 'parents', TableDraft::fromArray([
            'columns' => [['original' => 'name', ...$this->column('name', 'string', ['length' => 80]), 'changed' => true]],
        ], 'parents')));

        $target->table('parents')->insert(['name' => 'Eltern C', 'code' => 'C']);
        $this->assertSame(1, $target->table('audits')->count());
        $this->assertSame(3, $target->table('parent_names')->count());
        $this->assertSame(2, $target->table('children')->count());
    }

    #[DataProvider('drivers')]
    public function test_changing_columns_to_their_current_definition_keeps_type_null_and_default(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $target->getSchemaBuilder()->create('typen', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->string('kurz', 40)->default("a'b");
            $table->text('lang')->nullable();
            $table->integer('zahl')->default(7);
            $table->smallInteger('klein')->nullable();
            $table->decimal('betrag', 10, 2)->default(0);
            $table->double('mass')->nullable();
            $table->boolean('aktiv')->default(true);
            $table->date('tag')->nullable();
            $table->time('uhrzeit')->nullable();
            $table->timestamp('erfasst')->nullable()->useCurrent();
            $table->json('daten')->nullable();
            $table->binary('roh')->nullable();

            if ($driver !== DatabaseDriver::Sqlite) {
                $table->char('code', 3)->nullable();
                $table->bigInteger('gross')->nullable();
                $table->uuid('kennung')->nullable();
            }

            if (in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true)) {
                $table->unsignedInteger('positiv')->default(1);
                $table->longText('riesig')->nullable();
            }

            if ($driver === DatabaseDriver::Postgres) {
                $table->jsonb('struktur')->nullable();
                $table->timestampTz('zeitpunkt')->nullable();
            }
        });

        if (in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true)) {
            // Laravels enum() verdoppelt das Anführungszeichen nicht — die Auswahl mit «'» deshalb von Hand.
            $target->statement("alter table typen add zustand enum('offen','o''k') not null default 'offen'");
        }

        $target->table('typen')->insert(['kurz' => 'x']);
        $connection = $this->savedConnection($settings);
        $schema = $this->sampleSchemaName($driver);
        $before = array_column($target->getSchemaBuilder()->getColumns($schema.'.typen'), null, 'name');
        $specs = app(SchemaPlans::class)->currentSpecs($target, $connection, $schema, 'typen');
        $draft = TableDraft::fromArray(['columns' => array_values(array_map(
            fn ($spec): array => [...$spec->toArray(), 'changed' => true, 'comment' => $driver === DatabaseDriver::Sqlite ? null : 'geprüft'],
            array_filter($specs, fn ($spec): bool => $spec->name !== 'id'),
        ))], 'typen');

        app(SchemaChanges::class)->apply($connection, User::factory()->create(), fn (Connection $write): SchemaPlan => app(SchemaPlans::class)->alter($write, $connection, $schema, 'typen', $draft));

        $after = array_column($target->getSchemaBuilder()->getColumns($schema.'.typen'), null, 'name');
        $shape = fn (array $columns): array => array_map(fn (array $column): array => [$column['type'], $column['nullable'], $column['default']], $columns);
        $this->assertSame($shape($before), $shape($after));
        $this->assertNotContains('custom', array_map(fn ($spec): string => $spec->type, $specs), 'Jeder Typ aus Laravel steht in der Liste des Treibers.');
        $this->assertSame('x', $target->table('typen')->value('kurz'));
    }

    /**
     * Elterntabelle mit zwei Zeilen, Kindtabelle mit zwei Zeilen per ON DELETE CASCADE darauf.
     *
     * @return array{0: DatabaseConnection, 1: string, 2: Connection}
     */
    private function parentWithChildren(DatabaseDriver $driver): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $schema = $target->getSchemaBuilder();
        $schema->create('parents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('code', 10)->unique();
            $table->text('note')->nullable();
            $table->string('legacy', 20)->nullable();
        });
        $schema->create('children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('parents')->cascadeOnDelete();
        });
        $target->table('parents')->insert([['name' => 'Eltern A', 'code' => 'A', 'note' => 'erste', 'legacy' => 'x'], ['name' => 'Eltern B', 'code' => 'B', 'note' => null, 'legacy' => null]]);
        $target->table('children')->insert([['parent_id' => 1], ['parent_id' => 1]]);

        return [$this->savedConnection($settings), $this->sampleSchemaName($driver), $target];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function column(string $name, string $type, array $extra = []): array
    {
        return ['name' => $name, 'type' => $type, 'nullable' => false, 'default' => ['kind' => 'none', 'value' => null], ...$extra];
    }

    /**
     * @return array<string, mixed>
     */
    private function existing(string $name): array
    {
        return ['original' => $name, 'name' => $name, 'type' => 'custom', 'custom' => 'x'];
    }

    /**
     * Was auf Zielverbindungen geschrieben wird — ohne Lesezugriffe und die PRAGMAs des Verfahrens.
     *
     * @return list<string>
     */
    private function recording(callable $action): array
    {
        $executed = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$executed): void {
            if (str_starts_with((string) $query->connectionName, 'target_') && preg_match('/^\s*(select|pragma|show|with|set)\b/i', $query->sql) !== 1) {
                $executed[] = $query->sql;
            }
        });

        $action();

        return $executed;
    }
}
