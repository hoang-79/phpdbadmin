<?php

namespace Tests\Feature\Services\Transfer;

use App\Connections\ConnectionFactory;
use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Jobs\RunExport;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Transfer\TransferCancelled;
use App\Services\Transfer\TransferProgress;
use App\Services\Transfer\TransferRunner;
use App\Support\QueueHeartbeat;
use App\Support\TransferSummary;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;

/**
 * Hintergrund oder Anfrage (B-07): mit Worker und gespeichertem Passwort in die Warteschlange — nur mit der Kennung;
 * ohne Worker oder mit Passwort nur in der Sitzung gleich in der Anfrage, mit Grenze. Abbrechen hält den Lauf an.
 */
class TransferRunnerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/auftrag-'.getmypid());
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

    public function test_with_a_worker_the_export_is_queued_carrying_only_its_id_and_the_job_runs_it(): void
    {
        Storage::fake(Transfer::DISK);
        config(['queue.default' => 'database']);
        Queue::fake([RunExport::class]);
        app(QueueHeartbeat::class)->beat();
        $transfer = $this->exportOf($this->sqliteSamples());

        app(TransferRunner::class)->start($transfer);

        $this->assertSame(TransferStatus::Queued, $transfer->refresh()->status);
        $this->assertTrue($transfer->in_background);
        Queue::assertPushed(RunExport::class, fn (RunExport $job): bool => $job->transferId === $transfer->id);

        (new RunExport($transfer->id))->handle(app(TransferRunner::class), app(TargetConnections::class));

        $this->assertSame(TransferStatus::Done, $transfer->refresh()->status);
        $this->assertSame(10, $transfer->rows);
        $this->assertSame(100, $transfer->progress);
        Storage::disk(Transfer::DISK)->assertExists((string) $transfer->file_path);
    }

    public function test_without_a_worker_the_export_runs_in_the_request(): void
    {
        Storage::fake(Transfer::DISK);
        config(['queue.default' => 'database', 'phpdbadmin.transfers.background' => false]);
        Queue::fake([RunExport::class]);
        $transfer = $this->exportOf($this->sqliteSamples());

        app(TransferRunner::class)->start($transfer);

        Queue::assertNotPushed(RunExport::class);
        $this->assertSame(TransferStatus::Done, $transfer->refresh()->status);
        $this->assertFalse($transfer->in_background);
    }

    public function test_queue_counts_as_worked_only_while_a_worker_reports_in(): void
    {
        config(['queue.default' => 'database']);
        $runner = app(TransferRunner::class);

        $this->assertFalse($runner->hasWorker(), 'Ohne Lebenszeichen kein Worker — sonst wartet der Auftrag ewig.');

        event(new JobPopping('database', 'default'));
        $this->assertTrue($runner->hasWorker());

        $this->travel(91)->seconds();
        $this->assertFalse($runner->hasWorker(), 'Ein verstummter Worker zählt nicht mehr.');
    }

    public function test_limit_without_worker_explains_rows_and_how_to_set_up_a_worker(): void
    {
        config(['phpdbadmin.transfers.sync_max_rows' => 100]);
        $connection = DatabaseConnection::factory()->sqlite($this->folder.'/x.sqlite')->create();

        $problem = TransferSummary::message(app(TransferRunner::class)->limitProblem($connection, rows: 1500));

        $this->assertSame('Ohne Hintergrund-Worker exportiert phpdbadmin höchstens 100 Zeilen in einer Anfrage; die Auswahl hat etwa 1’500. Richten Sie einen Worker ein (php artisan queue:work) oder wählen Sie weniger.', $problem['text'] ?? null);
        $this->assertNull(app(TransferRunner::class)->limitProblem($connection, rows: 100));
    }

    #[Group('target-databases')]
    public function test_password_only_in_session_runs_in_the_request_even_with_a_worker(): void
    {
        Storage::fake(Transfer::DISK);
        config(['queue.default' => 'database', 'phpdbadmin.transfers.sync_max_rows' => 5]);
        Queue::fake([RunExport::class]);
        app(QueueHeartbeat::class)->beat();
        $settings = $this->scratchSettings(DatabaseDriver::MariaDb, $this->folder);
        $this->createTransferSamples(app(ConnectionFactory::class)->make($settings), DatabaseDriver::MariaDb);
        $connection = $this->savedConnection($settings, attributes: ['store_password' => false, 'password' => null]);
        app(TargetConnections::class)->rememberPassword($connection, (string) $settings->password);
        $runner = app(TransferRunner::class);

        $this->assertFalse($runner->runsInBackground($connection));
        $this->assertStringStartsWith('Diese Verbindung speichert ihr Passwort nicht', TransferSummary::message($runner->limitProblem($connection, rows: 10))['text'] ?? '');

        $transfer = $this->exportOf($connection, $this->targetTestDatabase(), 'positionen');
        $runner->start($transfer);

        Queue::assertNotPushed(RunExport::class);
        $this->assertSame(TransferStatus::Done, $transfer->refresh()->status, json_encode($transfer->error) ?: '');
    }

    #[Group('target-databases')]
    public function test_job_without_stored_password_fails_with_explanation(): void
    {
        Storage::fake(Transfer::DISK);
        $settings = $this->scratchSettings(DatabaseDriver::MariaDb, $this->folder);
        $connection = $this->savedConnection($settings, attributes: ['store_password' => false, 'password' => null]);
        $transfer = $this->exportOf($connection, $this->targetTestDatabase(), 'positionen');
        $transfer->forceFill(['status' => TransferStatus::Queued])->save();

        (new RunExport($transfer->id))->handle(app(TransferRunner::class), app(TargetConnections::class));

        $this->assertSame(TransferStatus::Failed, $transfer->refresh()->status);
        $this->assertStringStartsWith('Diese Verbindung speichert ihr Passwort nicht, und im Hintergrund gibt es keines.', TransferSummary::message($transfer->error)['text'] ?? '');
    }

    public function test_cancelled_transfer_is_not_started_by_its_job(): void
    {
        Storage::fake(Transfer::DISK);
        $transfer = $this->exportOf($this->sqliteSamples());
        $transfer->forceFill(['status' => TransferStatus::Cancelled])->save();

        (new RunExport($transfer->id))->handle(app(TransferRunner::class), app(TargetConnections::class));

        $this->assertSame(TransferStatus::Cancelled, $transfer->refresh()->status);
        $this->assertNull($transfer->file_path);
    }

    public function test_progress_stops_a_run_once_the_person_cancelled(): void
    {
        $transfer = Transfer::factory()->create(['status' => TransferStatus::Running]);
        $progress = new TransferProgress($transfer, interval: 0.0);
        $progress->report(10, 0.1);
        Transfer::query()->whereKey($transfer->id)->update(['status' => TransferStatus::Cancelled->value]);

        $this->expectException(TransferCancelled::class);
        $progress->report(20, 0.2);
    }

    public function test_export_cancelled_while_reading_stops_and_leaves_no_file(): void
    {
        Storage::fake(Transfer::DISK);
        $transfer = $this->exportOf($this->sqliteSamples());
        // Sobald der Export die Zieltabelle liest, bricht die Person ab (zweite Anfrage).
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($transfer): void {
            if (str_starts_with($query->connectionName, 'target_') && str_contains($query->sql, '"kunden"')) {
                Transfer::query()->whereKey($transfer->id)->update(['status' => TransferStatus::Cancelled->value]);
            }
        });

        app(TransferRunner::class)->start($transfer);

        $this->assertSame(TransferStatus::Cancelled, $transfer->refresh()->status);
        $this->assertNull($transfer->file_path);
        $this->assertSame([], Storage::disk(Transfer::DISK)->allFiles($transfer->directory()));
    }

    private function sqliteSamples(): DatabaseConnection
    {
        $settings = $this->scratchSettings(DatabaseDriver::Sqlite, $this->folder);
        $this->createTransferSamples(app(ConnectionFactory::class)->make($settings), DatabaseDriver::Sqlite);

        return $this->savedConnection($settings);
    }

    private function exportOf(DatabaseConnection $connection, string $schema = 'main', string $table = 'kunden'): Transfer
    {
        return Transfer::factory()->for($connection->owner)->for($connection, 'databaseConnection')->create([
            'kind' => TransferKind::Export,
            'format' => TransferFormat::Csv,
            'status' => TransferStatus::Draft,
            'target' => $schema.'.'.$table,
            'options' => ['scope' => 'table', 'schema' => $schema, 'table' => $table, 'header' => true],
            'file_name' => $table.'.csv',
        ]);
    }
}
