<?php

namespace Tests\Feature\Services\Transfer;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;

/**
 * Abnahme E-01: Export und Re-Import ergeben dieselben Daten — je Treiber, für SQL (ganze Datenbank: Struktur und
 * Daten) und CSV (eine Tabelle), mit Umlauten, NULL gegen leeren Text, Binärdaten, Zahlen, Wahrheitswerten und JSON.
 */
#[Group('target-databases')]
class RoundTripTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/austausch-'.getmypid());
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
    public function test_sql_export_of_whole_database_reimports_into_empty_database_with_same_data(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->samples($driver);
        $before = $this->allTables($writer);

        $export = $this->runTransfer($connection, TransferKind::Export, TransferFormat::Sql, ['scope' => 'schema', 'schema' => $schema, 'content' => 'both']);
        $file = $this->exportedFile($export);
        $this->assertSame(10 + 3 + 5 + 4, $export->rows);

        $this->emptyDatabase($writer, $driver);
        $import = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Sql, ['schema' => $schema], $file);

        $this->assertSame(TransferStatus::Done, $import->status, json_encode($import->error) ?: '');
        $this->assertEquals($before, $this->allTables($writer));
        $this->assertSame(5, (int) $writer->table('aktive_kunden')->count());

        // Schlüssel zählen nach dem Import weiter (PostgreSQL: setval; die übrigen von selbst).
        $this->assertSame(11, (int) $writer->table('kunden')->insertGetId(['name' => 'Neu']));
    }

    #[DataProvider('drivers')]
    public function test_csv_export_of_table_reimports_with_same_data(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->samples($driver);
        $before = $this->snapshot($writer, 'kunden');

        $export = $this->runTransfer($connection, TransferKind::Export, TransferFormat::Csv, [
            'scope' => 'table', 'schema' => $schema, 'table' => 'kunden', 'delimiter' => ';', 'bom' => true, 'header' => true, 'binary' => 'base64',
        ]);
        $file = $this->exportedFile($export);
        $this->assertStringStartsWith("\xEF\xBB\xBFid;name;notiz;betrag;wert;aktiv;foto;erstellt;meta\r\n", (string) file_get_contents($file));

        $writer->table('bestellungen')->update(['vorgaenger_id' => null]);
        $writer->table('bestellungen')->delete();
        $writer->table('kunden')->delete();
        $columns = ['id', 'name', 'notiz', 'betrag', 'wert', 'aktiv', 'foto', 'erstellt', 'meta'];
        $import = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Csv, [
            'schema' => $schema, 'table' => 'kunden', 'delimiter' => ';', 'encoding' => 'utf-8', 'header' => true,
            'columns' => array_map(fn (string $name, int $index): array => ['index' => $index, 'name' => $name, 'samples' => []], $columns, array_keys($columns)),
            'mapping' => $columns, 'on_error' => 'abort', 'analyze' => false,
        ], $file);

        $this->assertSame(TransferStatus::Done, $import->status, json_encode($import->error) ?: '');
        $this->assertSame(0, $import->result['error_count'] ?? null);
        $this->assertEquals($before, $this->snapshot($writer, 'kunden'));
    }

    #[DataProvider('drivers')]
    public function test_json_export_keeps_types_null_binary_and_embedded_json(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [, $connection, $schema] = $this->samples($driver);

        $export = $this->runTransfer($connection, TransferKind::Export, TransferFormat::Json, ['scope' => 'table', 'schema' => $schema, 'table' => 'kunden']);
        $rows = json_decode((string) file_get_contents($this->exportedFile($export)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(10, $rows);
        $this->assertSame(array_values(self::TRICKY_TEXTS), array_column($rows, 'name'));
        $this->assertNull($rows[0]['notiz']);
        $this->assertSame(base64_encode("\x00\x01\xFF\xFEabc"), $rows[0]['foto']);
        // JSON-Spalten eingebettet, wo der Treiber sie als JSON kennt; MariaDB und SQLite speichern sie als Text.
        $this->assertEquals(
            in_array($driver, [DatabaseDriver::MySql, DatabaseDriver::Postgres], true) ? ['a' => [1, 2], 'b' => 1, 'ä' => 'ö'] : '{"b": 1, "a": [1, 2], "ä": "ö"}',
            $rows[0]['meta'],
        );
        $this->assertSame(0.30000000000000004, $rows[1]['wert']);
        $this->assertContains($rows[0]['aktiv'], [true, 1]);
        $this->assertContains($rows[1]['aktiv'], [false, 0]);
    }

    #[DataProvider('drivers')]
    public function test_sql_export_of_structure_only_creates_empty_tables(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->samples($driver);

        $export = $this->runTransfer($connection, TransferKind::Export, TransferFormat::Sql, ['scope' => 'schema', 'schema' => $schema, 'content' => 'structure', 'drop' => true]);
        $sql = (string) file_get_contents($this->exportedFile($export));
        $this->assertStringNotContainsString('INSERT INTO', $sql);

        // Mit «ersetzen» lässt sich die Struktur über die bestehende Datenbank spielen.
        $import = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Sql, ['schema' => $schema], $this->exportedFile($export));

        $this->assertSame(TransferStatus::Done, $import->status, json_encode($import->error) ?: '');
        $this->assertSame(0, (int) $writer->table('kunden')->count());
        $this->assertSame(0, (int) $writer->table('aktive_kunden')->count());
    }

    /**
     * @return array{0: Connection, 1: DatabaseConnection, 2: string}
     */
    private function samples(DatabaseDriver $driver): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $writer = app(ConnectionFactory::class)->make($settings);
        $this->createTransferSamples($writer, $driver);

        return [$writer, $this->savedConnection($settings), $this->sampleSchemaName($driver)];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function allTables(Connection $connection): array
    {
        return array_combine(
            ['kunden', 'bestellungen', 'positionen', 'protokoll'],
            array_map(fn (string $table): array => $this->snapshot($connection, $table), ['kunden', 'bestellungen', 'positionen', 'protokoll']),
        );
    }
}
