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
 * Messtest E-01: eine Million Zeilen exportieren (CSV und SQL) unter 64 MB — gemessen am PHP-Speicher und an der
 * Prozessgrösse (libpq hält Ergebnisse ausserhalb des PHP-Speichers, F-8). Dazu der Re-Import beider Dateien mit
 * Dauer und Speicher. Schwer — läuft nur mit PROBE_GROSS=1 (sonst ausdrücklich übersprungen):
 *
 * PROBE_GROSS=1 PHPDBADMIN_TEST_DATABASE=… php vendor/bin/phpunit --group transfer-large
 *
 * Die Zahlen landen in storage/framework/testing/transfer-messung.json (für das Protokoll der Timebox).
 */
#[Group('transfer-large')]
#[Group('target-databases')]
class LargeTransferTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private const int ROWS = 1_000_000;

    private const int LIMIT_MB = 64;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PROBE_GROSS') !== '1') {
            $this->markTestSkipped('Schwerer Messtest: nur mit PROBE_GROSS=1');
        }

        $this->folder = storage_path('framework/testing/gross-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'phpdbadmin.connections.sqlite_paths' => [$this->folder],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->folder)) {
            exec('rm -rf '.escapeshellarg($this->folder));
        }

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
    public function test_million_rows_export_and_reimport_with_bounded_memory(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        $settings = $this->scratchSettings($driver, $this->folder);
        $writer = app(ConnectionFactory::class)->make($settings);
        $this->fillMillionRows($writer, $driver);
        $connection = $this->savedConnection($settings);
        $schema = $this->sampleSchemaName($driver);
        $findings = [];

        [$csv, $findings['export_csv']] = $this->measured(fn (): Transfer => $this->runTransfer($connection, TransferKind::Export, TransferFormat::Csv, [
            'scope' => 'table', 'schema' => $schema, 'table' => 'messung', 'delimiter' => ',', 'header' => true,
        ]));
        [$sql, $findings['export_sql']] = $this->measured(fn (): Transfer => $this->runTransfer($connection, TransferKind::Export, TransferFormat::Sql, [
            'scope' => 'table', 'schema' => $schema, 'table' => 'messung', 'content' => 'data',
        ]));
        $findings['export_csv']['datei_mb'] = round((int) $csv->file_size / 1048576, 1);
        $findings['export_sql']['datei_mb'] = round((int) $sql->file_size / 1048576, 1);

        $this->assertSame(self::ROWS, $csv->rows);
        $this->assertSame(self::ROWS, $sql->rows);
        $csvFile = $this->copyOut($csv);
        $sqlFile = $this->copyOut($sql);

        $writer->table('messung')->truncate();
        [$csvImport, $findings['import_csv']] = $this->measured(fn (): Transfer => $this->runTransfer($connection, TransferKind::Import, TransferFormat::Csv, [
            'schema' => $schema, 'table' => 'messung', 'delimiter' => ',', 'encoding' => 'utf-8', 'header' => true,
            'columns' => array_map(fn (string $name, int $index): array => ['index' => $index, 'name' => $name, 'samples' => []], ['id', 'category', 'label', 'amount', 'created_at'], range(0, 4)),
            'mapping' => ['id', 'category', 'label', 'amount', 'created_at'], 'on_error' => 'abort', 'analyze' => true,
        ], $csvFile));
        $this->assertSame(TransferStatus::Done, $csvImport->status, json_encode($csvImport->error) ?: '');
        $this->assertSame(self::ROWS, (int) $writer->table('messung')->count());

        $writer->table('messung')->truncate();
        [$sqlImport, $findings['import_sql']] = $this->measured(fn (): Transfer => $this->runTransfer($connection, TransferKind::Import, TransferFormat::Sql, ['schema' => $schema], $sqlFile));
        $this->assertSame(TransferStatus::Done, $sqlImport->status, json_encode($sqlImport->error) ?: '');
        $this->assertSame(self::ROWS, (int) $writer->table('messung')->count());

        $this->record($driver, $findings);

        foreach (['export_csv', 'export_sql'] as $step) {
            $this->assertLessThan(self::LIMIT_MB, $findings[$step]['php_mb'], $step.': PHP-Speicher');
            $this->assertLessThan(self::LIMIT_MB, $findings[$step]['prozess_mb'], $step.': Prozessgrösse');
        }
    }

    /**
     * Dauer, Spitze des PHP-Speichers und Wachstum der Prozessgrösse (ru_maxrss: macOS Bytes, Linux KB) eines Schritts.
     *
     * @param  callable(): Transfer  $step
     * @return array{0: Transfer, 1: array{sekunden: float, php_mb: float, prozess_mb: float}}
     */
    private function measured(callable $step): array
    {
        gc_collect_cycles();
        $baseline = memory_get_usage();
        memory_reset_peak_usage();
        $rssBefore = $this->maxRss();
        $start = hrtime(true);

        $transfer = $step();

        return [$transfer, [
            'sekunden' => round((hrtime(true) - $start) / 1e9, 1),
            'php_mb' => round((memory_get_peak_usage() - $baseline) / 1048576, 1),
            'prozess_mb' => round(max(0, $this->maxRss() - $rssBefore) / 1048576, 1),
        ]];
    }

    private function maxRss(): int
    {
        $rss = (int) (getrusage()['ru_maxrss'] ?? 0);

        return PHP_OS_FAMILY === 'Darwin' ? $rss : $rss * 1024;
    }

    private function fillMillionRows(Connection $connection, DatabaseDriver $driver): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->create('ziffern', fn (Blueprint $table) => $table->integer('d'));
        $connection->table('ziffern')->insert(array_map(fn (int $d): array => ['d' => $d], range(0, 9)));
        $schema->create('messung', function (Blueprint $table) {
            $table->id();
            $table->integer('category');
            $table->string('label', 60);
            $table->decimal('amount', 10, 2);
            $table->dateTime('created_at');
        });

        [$label, $timestamp] = match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => ["concat('Eintrag ', n)", "date_add('2026-01-01 00:00:00', interval n second)"],
            DatabaseDriver::Postgres => ["'Eintrag ' || n", "timestamp '2026-01-01 00:00:00' + n * interval '1 second'"],
            DatabaseDriver::Sqlite => ["'Eintrag ' || n", "datetime('2026-01-01 00:00:00', '+' || n || ' seconds')"],
        };

        $connection->statement(
            "insert into messung (id, category, label, amount, created_at)
             select n + 1, n % 100, {$label}, (n % 10000) / 100.0, {$timestamp}
             from (select a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d + 100000 * g.d as n
                   from ziffern a cross join ziffern b cross join ziffern c
                   cross join ziffern e cross join ziffern f cross join ziffern g) zahlen"
        );
        $schema->drop('ziffern');
    }

    /** Die Datei aus dem Auftrag kopieren — der Import legt sie in seinen eigenen Ordner. */
    private function copyOut(Transfer $transfer): string
    {
        $target = $this->folder.'/'.$transfer->file_name;
        copy($this->exportedFile($transfer), $target);

        return $target;
    }

    /**
     * @param  array<string, mixed>  $findings
     */
    private function record(DatabaseDriver $driver, array $findings): void
    {
        $path = storage_path('framework/testing/transfer-messung.json');
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all[$driver->value] = $findings;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
