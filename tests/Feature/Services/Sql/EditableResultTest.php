<?php

namespace Tests\Feature\Services\Sql;

use App\Connections\ConnectionFactory;
use App\Enums\ConnectionPermission;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Data\RowEditor;
use App\Services\Sql\EditableResult;
use App\Services\Sql\QueryRunner;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Ergebnisse bearbeiten (Q-05) je Treiber: bearbeitbar, wenn die Abfrage eine Tabelle mit vollständigem
 * Primärschlüssel liest; nur lesen mit Grund bei Join, Ausdruck im Schlüssel, fehlendem Schlüssel, Tabelle ohne
 * Schlüssel, Sicht, fehlendem Recht und Schreibschutz. Die Änderung läuft danach über den RowEditor.
 */
#[Group('target-databases')]
class EditableResultTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    private Connection $writer;

    private DatabaseConnection $connection;

    private User $user;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/ergebnis-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
            'cache.default' => 'array',
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
    public function test_result_of_one_table_with_its_key_is_editable_and_the_change_applies(DatabaseDriver $driver): void
    {
        $this->shop($driver);

        $source = $this->source('select c.name as n, c.id, upper(c.email) as e, c.active from '.$this->schema.'.customers c where c.id > 0 order by c.id');

        $this->assertTrue($source['editable'], (string) $source['reason']);
        $this->assertSame([$this->schema, 'customers', [1]], [$source['schema'], $source['table'], $source['keyColumns']]);
        $this->assertSame(['name', null, null, 'active'], array_map(fn (?array $column): ?string => $column['name'] ?? null, $source['columns']));
        $this->assertSame('boolean', $source['columns'][3]['kind'] ?? null);

        // Bearbeitet wird wie im Raster: Schlüssel aus der Zeile des Ergebnisses, eine Transaktion, Rückgängig.
        $result = app(RowEditor::class)->apply($this->connection, $this->user, null, (string) $source['schema'], (string) $source['table'], [
            ['op' => 'update', 'key' => ['2'], 'values' => ['name' => 'Beat Grün', 'active' => true]],
        ]);
        $this->assertSame(['Anna', 'Beat Grün'], $this->writer->table('customers')->orderBy('id')->pluck('name')->all());

        app(RowEditor::class)->undo($this->connection, $this->user, $result['auditId']);
        $this->assertSame(['Anna', 'Beat'], $this->writer->table('customers')->orderBy('id')->pluck('name')->all());
    }

    #[DataProvider('drivers')]
    public function test_star_without_schema_maps_every_column(DatabaseDriver $driver): void
    {
        $this->shop($driver);

        $source = $this->source('SELECT * FROM customers');

        $this->assertTrue($source['editable'], (string) $source['reason']);
        $this->assertSame([0], $source['keyColumns']);
        $this->assertSame([null, 'name', 'email', 'active', null], array_map(fn (?array $column): ?string => $column['name'] ?? null, $source['columns']), 'Schlüssel und Binärdaten nicht');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function readOnlyQueries(): array
    {
        return [
            'Join' => ['select c.id, c.name, o.total from customers c join orders o on o.customer_id = c.id', 'mehrere Tabellen'],
            'Ausdruck im Schlüssel' => ['select id + 0 as id, name from customers', 'Primärschlüssel nicht vollständig (es fehlt: id)'],
            'Schlüssel fehlt' => ['select name, email from customers', 'Primärschlüssel nicht vollständig'],
            'ohne Primärschlüssel' => ['select * from logs', 'ohne Primärschlüssel'],
            'Sicht' => ['select * from customer_names', 'Sichten und Tabellen ohne Primärschlüssel'],
            'nur der Schlüssel' => ['select id from customers where id in (select 1)', 'Keine Spalte des Ergebnisses'],
            'gruppiert' => ['select active, count(*) as n from customers group by active', 'Gruppierte Zeilen'],
        ];
    }

    #[DataProvider('drivers')]
    public function test_other_queries_stay_read_only_with_a_reason(DatabaseDriver $driver): void
    {
        $this->shop($driver);

        foreach (self::readOnlyQueries() as $case => [$sql, $reason]) {
            $source = $this->source($sql);

            $this->assertFalse($source['editable'], $case);
            $this->assertStringContainsString($reason, (string) $source['reason'], $case);
        }

        $this->assertNull($this->source('select 1 + 1 as zwei')['schema'] ?? null);
    }

    #[DataProvider('drivers')]
    public function test_reader_and_read_only_connection_see_why_they_cannot_edit(DatabaseDriver $driver): void
    {
        $this->shop($driver);
        $reader = User::factory()->create();
        $this->connection->forceFill(['shared' => true])->save();
        $this->connection->members()->attach($reader, ['permission' => ConnectionPermission::Read]);

        $asReader = $this->source('select * from customers', $reader);
        $this->assertFalse($asReader['editable']);
        $this->assertSame('Ihr Konto darf über diese Verbindung nur lesen.', $asReader['reason']);

        $this->connection->forceFill(['read_only' => true])->save();
        $readOnly = $this->source('select * from customers');
        $this->assertFalse($readOnly['editable']);
        $this->assertStringStartsWith('Diese Verbindung ist schreibgeschützt', (string) $readOnly['reason']);
    }

    /**
     * @return array{editable: bool, reason: string|null, schema: string|null, table: string|null, keyColumns: list<int>, columns: list<array<string, mixed>|null>}
     */
    private function source(string $sql, ?User $user = null): array
    {
        $results = app(QueryRunner::class)->run($this->connection, null, [$sql], 'lauf-'.bin2hex(random_bytes(4)), false, 100, ($user ?? $this->user)->id);
        $this->assertSame('ok', $results[0]['status'], (string) $results[0]['message']);

        $source = app(EditableResult::class)->describe($this->connection->refresh(), $user ?? $this->user, null, $this->schema, $results[0]);
        $this->assertNotNull($source);

        return $source;
    }

    private function shop(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->writer = app(ConnectionFactory::class)->make($settings);
        $this->connection = $this->savedConnection($settings, $this->user);
        $this->schema = $this->sampleSchemaName($driver);

        $schema = $this->writer->getSchemaBuilder();
        $schema->create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->boolean('active')->default(false);
            $table->binary('photo')->nullable();
        });
        $schema->create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('total', 10, 2);
        });
        $schema->create('logs', function (Blueprint $table) {
            $table->string('message');
        });
        $this->writer->statement('create view customer_names as select id, name from customers');

        $this->writer->table('customers')->insert([['name' => 'Anna', 'email' => 'anna@example.ch'], ['name' => 'Beat', 'email' => 'beat@example.ch']]);
        $this->writer->table('orders')->insert([['customer_id' => 1, 'total' => 10]]);
        $this->writer->table('logs')->insert([['message' => 'eins']]);
    }
}
