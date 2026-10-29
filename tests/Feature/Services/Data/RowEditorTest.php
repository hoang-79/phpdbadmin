<?php

namespace Tests\Feature\Services\Data;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\AuditEntry;
use App\Models\ChangeSnapshot;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Data\ChangeConflict;
use App\Services\Data\ChangeRejected;
use App\Services\Data\RowEditor;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Bearbeiten je Treiber (D-04, D-05, D-07, D-09, N-03): nichts geschrieben vor «Anwenden», alles in einer
 * Transaktion, Kaskaden in der Vorschau gezählt, Rückgängig stellt den Ausgangszustand her, Werte byte-genau.
 */
#[Group('target-databases')]
class RowEditorTest extends TestCase
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

        $this->folder = storage_path('framework/testing/bearbeiten-'.getmypid());
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
    public function test_preview_writes_nothing_and_counts_cascading_children(DatabaseDriver $driver): void
    {
        $this->shop($driver);

        $preview = $this->editor()->preview($this->connection, null, $this->schema, 'customers', [
            ['op' => 'update', 'key' => ['1'], 'values' => ['name' => "O'Brien"]],
            ['op' => 'delete', 'key' => ['2'], 'values' => null],
        ]);

        $this->assertCount(2, $preview['statements']);
        // Werte eingesetzt und gequotet — MySQL/MariaDB mit Backslash, die anderen mit verdoppeltem Hochkomma.
        $this->assertMatchesRegularExpression("/'O(''|\\\\')Brien'/", $preview['statements'][0]['sql']);
        $this->assertSame([['table' => 'orders', 'count' => 2]], $preview['statements'][1]['cascades']);
        $this->assertSame(['Anna', 'Beat'], $this->names());
        $this->assertSame(3, $this->writer->table('orders')->count());
    }

    #[DataProvider('drivers')]
    public function test_applies_all_changes_in_one_transaction_and_logs_without_values(DatabaseDriver $driver): void
    {
        $this->shop($driver);

        $result = $this->editor()->apply($this->connection, $this->user, null, $this->schema, 'customers', [
            ['op' => 'update', 'key' => ['1'], 'values' => ['name' => 'Anna Muster']],
            ['op' => 'insert', 'key' => null, 'values' => ['name' => 'Carla', 'balance' => '7.50']],
            ['op' => 'delete', 'key' => ['2'], 'values' => null],
        ]);

        $this->assertSame(['inserted' => 1, 'updated' => 1, 'deleted' => 1], array_intersect_key($result, array_flip(['inserted', 'updated', 'deleted'])));
        $this->assertSame(['Anna Muster', 'Carla'], $this->names());
        $this->assertSame(1, $this->writer->table('orders')->count(), 'Kinder von Beat per Kaskade weg');

        $entry = AuditEntry::query()->findOrFail($result['auditId']);
        $this->assertSame('data.change', $entry->action);
        $this->assertStringNotContainsString('Anna Muster', (string) $entry->sql);
        $this->assertStringNotContainsString('Carla', (string) $entry->sql);
        $this->assertSame(3, ChangeSnapshot::query()->where('audit_log_id', $result['auditId'])->count());
    }

    #[DataProvider('drivers')]
    public function test_error_in_the_second_statement_rolls_back_the_first(DatabaseDriver $driver): void
    {
        $this->shop($driver);

        try {
            $this->editor()->apply($this->connection, $this->user, null, $this->schema, 'customers', [
                ['op' => 'update', 'key' => ['1'], 'values' => ['name' => 'Geändert']],
                ['op' => 'insert', 'key' => null, 'values' => ['name' => 'Beat']],
            ]);
            $this->fail('Doppelter Name wurde angenommen');
        } catch (QueryException) {
            $this->assertSame(['Anna', 'Beat'], $this->names());
        }

        $this->assertSame(0, AuditEntry::query()->where('action', 'data.change')->count());
    }

    #[DataProvider('drivers')]
    public function test_change_that_misses_its_row_changes_nothing(DatabaseDriver $driver): void
    {
        $this->shop($driver);

        try {
            $this->editor()->apply($this->connection, $this->user, null, $this->schema, 'customers', [
                ['op' => 'update', 'key' => ['1'], 'values' => ['name' => 'Geändert']],
                ['op' => 'delete', 'key' => ['999'], 'values' => null],
            ]);
            $this->fail('Fehlende Zeile wurde nicht bemerkt');
        } catch (ChangeConflict) {
            $this->assertSame(['Anna', 'Beat'], $this->names());
        }
    }

    #[DataProvider('drivers')]
    public function test_undo_restores_the_original_state(DatabaseDriver $driver): void
    {
        $this->shop($driver);
        $original = $this->snapshotOfTables();

        $result = $this->editor()->apply($this->connection, $this->user, null, $this->schema, 'orders', [
            ['op' => 'update', 'key' => ['1'], 'values' => ['total' => '99.90']],
            ['op' => 'insert', 'key' => null, 'values' => ['customer_id' => 1, 'total' => '1.00']],
            ['op' => 'delete', 'key' => ['3'], 'values' => null],
        ]);
        $this->assertNotEquals($original, $this->snapshotOfTables());

        $this->editor()->undo($this->connection, $this->user, $result['auditId']);

        $this->assertEquals($original, $this->snapshotOfTables());
        $this->expectException(ChangeRejected::class);
        $this->editor()->undo($this->connection, $this->user, $result['auditId']);
    }

    #[DataProvider('drivers')]
    public function test_undo_refuses_to_overwrite_a_later_change(DatabaseDriver $driver): void
    {
        $this->shop($driver);
        $result = $this->editor()->apply($this->connection, $this->user, null, $this->schema, 'customers', [
            ['op' => 'update', 'key' => ['1'], 'values' => ['name' => 'Zwischenstand']],
        ]);
        $this->writer->table('customers')->where('id', 1)->update(['name' => 'Jemand anderes']);

        $this->expectException(ChangeConflict::class);

        try {
            $this->editor()->undo($this->connection, $this->user, $result['auditId']);
        } finally {
            $this->assertSame(['Beat', 'Jemand anderes'], $this->names());
        }
    }

    #[DataProvider('drivers')]
    public function test_values_are_written_byte_exact(DatabaseDriver $driver): void
    {
        $this->shop($driver);
        $values = [
            'name' => "Grüezi 👋 'quote' \\ \"x\"",
            'note' => '',
            'balance' => '12.30',
            'seen_at' => '2026-10-28 10:22:41',
            'active' => true,
            'meta' => '{"ort": "Zürich", "plz": 8001}',
        ];

        $this->editor()->apply($this->connection, $this->user, null, $this->schema, 'customers', [
            ['op' => 'update', 'key' => ['1'], 'values' => $values],
            ['op' => 'update', 'key' => ['2'], 'values' => ['note' => null, 'active' => false]],
        ]);

        $first = (array) $this->writer->table('customers')->where('id', 1)->first();
        $second = (array) $this->writer->table('customers')->where('id', 2)->first();
        $this->assertSame($values['name'], $first['name']);
        $this->assertSame('', $first['note'], 'leerer Text bleibt leer, nicht NULL');
        $this->assertNull($second['note'], 'NULL bleibt NULL');
        $this->assertEquals('12.30', $first['balance']);
        $this->assertSame('2026-10-28 10:22:41', substr((string) $first['seen_at'], 0, 19));
        $this->assertTrue((bool) $first['active']);
        $this->assertFalse((bool) $second['active']);
        $this->assertEquals(['ort' => 'Zürich', 'plz' => 8001], json_decode((string) $first['meta'], true));
    }

    #[DataProvider('drivers')]
    public function test_rejects_invalid_json_and_tables_without_primary_key(DatabaseDriver $driver): void
    {
        $this->shop($driver);
        $this->writer->getSchemaBuilder()->create('log_lines', fn (Blueprint $table) => $table->string('line'));
        $this->writer->table('log_lines')->insert([['line' => 'a'], ['line' => 'a']]);

        // SQLite kennt keinen JSON-Typ (Laravel legt text an) — dort ist es ein gewöhnlicher Text.
        if ($driver !== DatabaseDriver::Sqlite) {
            try {
                $this->editor()->apply($this->connection, $this->user, null, $this->schema, 'customers', [
                    ['op' => 'update', 'key' => ['1'], 'values' => ['meta' => '{kaputt']],
                ]);
                $this->fail('Ungültiges JSON angenommen');
            } catch (ChangeRejected) {
            }
        }

        $this->expectException(ChangeRejected::class);

        try {
            $this->editor()->apply($this->connection, $this->user, null, $this->schema, 'log_lines', [
                ['op' => 'update', 'key' => ['a'], 'values' => ['line' => 'b']],
            ]);
        } finally {
            $this->assertSame(['a', 'a'], $this->writer->table('log_lines')->pluck('line')->all(), 'mehrdeutige Zeile unverändert');
        }
    }

    private function editor(): RowEditor
    {
        return app(RowEditor::class);
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
            $table->string('name')->unique();
            $table->text('note')->nullable();
            $table->decimal('balance', 10, 2)->default(0);
            $table->dateTime('seen_at')->nullable();
            $table->boolean('active')->default(false);
            $table->json('meta')->nullable();
        });
        $schema->create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('total', 10, 2);
        });

        if ($driver === DatabaseDriver::Sqlite) {
            $this->writer->statement('pragma foreign_keys = on');
        }

        $this->writer->table('customers')->insert([['name' => 'Anna', 'note' => 'erste'], ['name' => 'Beat', 'note' => 'zweite']]);
        $this->writer->table('orders')->insert([['customer_id' => 1, 'total' => 10], ['customer_id' => 2, 'total' => 20], ['customer_id' => 2, 'total' => 30]]);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return $this->writer->table('customers')->orderBy('name')->pluck('name')->all();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshotOfTables(): array
    {
        return [
            'customers' => $this->writer->table('customers')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'orders' => $this->writer->table('orders')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
