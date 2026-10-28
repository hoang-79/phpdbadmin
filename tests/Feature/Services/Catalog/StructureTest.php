<?php

namespace Tests\Feature\Services\Catalog;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Services\Catalog\Structure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Struktur ansehen (S-01): je Treiber gegen eine Beispieltabelle mit allen Merkmalen — Spalten mit Art (O-04),
 * Indizes, Fremdschlüssel in beide Richtungen, Check-Constraints, Trigger, DDL.
 */
#[Group('target-databases')]
class StructureTest extends TestCase
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
    public function test_describes_table_with_all_features(DatabaseDriver $driver): void
    {
        $structure = $this->describe($driver, 'customers');

        $this->assertSame('table', $structure['object']['kind']);
        $columns = array_column($structure['columns'], null, 'name');
        $this->assertSame(['id', 'email', 'name', 'status', 'balance', 'meta', 'created_at'], array_keys($columns));

        $this->assertTrue($columns['id']['primary']);
        $this->assertTrue($columns['id']['autoIncrement']);
        $this->assertSame('number', $columns['id']['kind']);
        $this->assertFalse($columns['email']['nullable']);
        $this->assertSame('text', $columns['email']['kind']);
        $this->assertTrue($columns['name']['nullable']);
        $this->assertSame('number', $columns['balance']['kind']);
        $this->assertNotNull($columns['balance']['default']);
        // Vorgabe als SQL-Literal in allen vier gleich (O-04): ohne «::character varying», MySQL mit Anführungszeichen.
        $this->assertSame("'aktiv'", $columns['status']['default']);
        $this->assertNull($columns['name']['default'], 'MariaDB meldet «keine Vorgabe» als Text NULL');
        $this->assertSame('datetime', $columns['created_at']['kind']);
        // Laravels enum() ist nur in MySQL und MariaDB ein eigener Typ, sonst Text mit Check; JSON hat SQLite nicht.
        $this->assertSame(in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true) ? 'choice' : 'text', $columns['status']['kind']);
        $this->assertSame($driver === DatabaseDriver::Sqlite ? 'text' : 'json', $columns['meta']['kind']);
        $this->assertSame($driver === DatabaseDriver::Sqlite ? null : 'Anzeigename', $columns['name']['comment']);

        $indexes = array_column($structure['indexes'], null, 'name');
        $this->assertNotEmpty(array_filter($structure['indexes'], fn (array $index) => $index['primary'] && $index['columns'] === ['id']));
        $this->assertNotEmpty(array_filter($structure['indexes'], fn (array $index) => $index['unique'] && ! $index['primary'] && $index['columns'] === ['email']));
        $this->assertNotEmpty($indexes);

        $this->assertSame([['orders']], array_map(fn (array $foreign) => [$foreign['table']], $structure['referencedBy']));
        $this->assertMatchesRegularExpression('/^create table/i', $structure['ddl']);
    }

    #[DataProvider('drivers')]
    public function test_describes_foreign_keys_checks_and_triggers(DatabaseDriver $driver): void
    {
        $structure = $this->describe($driver, 'orders');

        $this->assertCount(1, $structure['foreignKeys']);
        $this->assertSame(['customer_id'], $structure['foreignKeys'][0]['columns']);
        $this->assertSame('customers', $structure['foreignKeys'][0]['foreignTable']);
        $this->assertSame('cascade', $structure['foreignKeys'][0]['onDelete']);
        $this->assertSame(['table' => 'customers', 'column' => 'id'], array_column($structure['columns'], null, 'name')['customer_id']['references']);
        $this->assertSame(['orders_total_check'], array_column($structure['checks'], 'name'));
        $this->assertSame(['orders_note_default'], array_column($structure['triggers'], 'name'));
    }

    #[DataProvider('drivers')]
    public function test_describes_view_with_columns_and_definition(DatabaseDriver $driver): void
    {
        $structure = $this->describe($driver, 'open_orders');

        $this->assertSame('view', $structure['object']['kind']);
        $this->assertSame(['id', 'customer_id', 'total'], array_column($structure['columns'], 'name'));
        $this->assertSame([], $structure['indexes']);
        $this->assertStringContainsString('open_orders', $structure['ddl']);
    }

    public function test_postgres_enum_type_is_a_choice(): void
    {
        $settings = $this->scratchSettings(DatabaseDriver::Postgres, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $target->statement('drop table if exists moods');
        $target->statement('drop type if exists vertrag_stimmung');
        $target->statement("create type vertrag_stimmung as enum ('froh', 'müde')");
        $target->statement('create table moods (id serial primary key, mood vertrag_stimmung not null)');

        try {
            $structure = app(Structure::class)->describe($this->savedConnection($settings), 'public', 'moods');
            $this->assertSame('choice', array_column($structure['columns'] ?? [], 'kind', 'name')['mood'] ?? null);
        } finally {
            $target->statement('drop table moods');
            $target->statement('drop type vertrag_stimmung');
        }
    }

    #[DataProvider('drivers')]
    public function test_unknown_object_is_null(DatabaseDriver $driver): void
    {
        $connection = $this->savedConnection($this->scratchSettings($driver, $this->folder));

        $this->assertNull(app(Structure::class)->describe($connection, $this->sampleSchemaName($driver), 'gibt_es_nicht'));
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(DatabaseDriver $driver, string $object): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->createSampleSchema(app(ConnectionFactory::class)->make($settings), $driver);

        $structure = app(Structure::class)->describe($this->savedConnection($settings), $this->sampleSchemaName($driver), $object);
        $this->assertNotNull($structure);

        return $structure;
    }
}
