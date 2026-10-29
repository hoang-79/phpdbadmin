<?php

namespace Tests\Feature\Services\Transfer;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\Transfer;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;

/**
 * Abnahme E-06: Nach einem eigenen Import ist die Schätzung der Zeilenzahl aktuell (Dialect::tables → rows). SQLite
 * schätzt nicht — dort zeigt sqlite_stat1, dass ANALYZE lief.
 */
#[Group('target-databases')]
class StatisticsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private const int ROWS = 500;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/statistik-'.getmypid());
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
    public function test_row_estimate_is_current_after_import(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        $settings = $this->scratchSettings($driver, $this->folder);
        $writer = app(ConnectionFactory::class)->make($settings);
        $writer->getSchemaBuilder()->create('messwerte', function (Blueprint $table) {
            $table->id();
            $table->string('ort');
            $table->integer('wert');
            $table->index('wert');
        });
        $connection = $this->savedConnection($settings);
        $schema = $this->sampleSchemaName($driver);

        // Vorher einmal lesen: MySQL hält die Zahl in information_schema bis zu 24 Stunden zwischengespeichert.
        $this->assertContains($this->estimate($writer, $driver, $schema), [null, 0]);

        $file = $this->folder.'/messwerte.csv';
        file_put_contents($file, "ort,wert\n".implode("\n", array_map(fn (int $i): string => 'Ort '.$i.','.$i, range(1, self::ROWS)))."\n");
        $transfer = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Csv, [
            'schema' => $schema, 'table' => 'messwerte', 'delimiter' => ',', 'encoding' => 'utf-8', 'header' => true,
            'columns' => [['index' => 0, 'name' => 'ort', 'samples' => []], ['index' => 1, 'name' => 'wert', 'samples' => []]],
            'mapping' => ['ort', 'wert'], 'on_error' => 'skip', 'analyze' => true,
        ], $file);

        $this->assertSame(TransferStatus::Done, $transfer->status, json_encode($transfer->error) ?: '');
        $this->assertTrue($transfer->result['analyzed'] ?? false);

        if ($driver === DatabaseDriver::Sqlite) {
            $stat = $writer->selectOne("select stat from sqlite_stat1 where tbl = 'messwerte' limit 1");
            $this->assertSame(self::ROWS, (int) strtok((string) $stat?->stat, ' '));

            return;
        }

        $this->assertEqualsWithDelta(self::ROWS, $this->estimate($writer, $driver, $schema), self::ROWS * 0.1);
    }

    private function estimate(Connection $writer, DatabaseDriver $driver, string $schema): ?int
    {
        $tables = $driver->dialect()->tables($writer, $schema);

        return array_find($tables, fn (array $table): bool => $table['name'] === 'messwerte')['rows'] ?? null;
    }
}
