<?php

namespace Tests\Feature\Services\Transfer;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Support\TransferSummary;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;

/**
 * SQL-Datei ausführen (E-02): Probelauf zählt nur, PostgreSQL und SQLite rollen bei einem Fehler alles zurück,
 * MySQL und MariaDB behalten, was davor lief — und schreiben es ins Protokoll.
 */
#[Group('target-databases')]
class SqlImporterTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/sql-'.getmypid());
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
    public function test_dry_run_counts_statements_flags_dangerous_ones_and_executes_nothing(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->database($driver);
        $file = $this->file("create table probe (a int);\ninsert into probe values (1);\ndrop table probe;\ndelete from probe;\nupdate probe set a = 2 where a = 1;\n");

        $transfer = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Sql, ['schema' => $schema], $file, dryRun: true);

        $this->assertSame(TransferStatus::Done, $transfer->status, json_encode($transfer->error) ?: '');
        $this->assertSame(5, $transfer->result['statements'] ?? null);
        $this->assertEquals(['CREATE' => 1, 'INSERT' => 1, 'DROP' => 1, 'DELETE' => 1, 'UPDATE' => 1], $transfer->result['kinds'] ?? null);
        $this->assertSame([['line' => 3, 'keyword' => 'DROP'], ['line' => 4, 'keyword' => 'DELETE']], $transfer->result['dangerous'] ?? null);
        $this->assertFalse($writer->getSchemaBuilder()->hasTable('probe'));
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[DataProvider('drivers')]
    public function test_failing_statement_names_its_line_and_rolls_back_where_the_driver_can(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->database($driver);
        $file = $this->file("create table probe (a int);\n\ninsert into probe values (1);\n-- Kommentar\ninsert into gibt_es_nicht values (1);\n");

        $transfer = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Sql, ['schema' => $schema], $file);
        $message = TransferSummary::message($transfer->error);

        $this->assertSame(TransferStatus::Failed, $transfer->status);
        $this->assertNotNull($message['detail'] ?? null);

        if ($driver === DatabaseDriver::Postgres || $driver === DatabaseDriver::Sqlite) {
            $this->assertSame('Anweisung 3 (Zeile 5) ist fehlgeschlagen. Nichts wurde geändert.', $message['text'] ?? null);
            $this->assertFalse($writer->getSchemaBuilder()->hasTable('probe'));
            $this->assertSame(0, AuditEntry::query()->count());

            return;
        }

        $this->assertSame('Anweisung 3 (Zeile 5) ist fehlgeschlagen. Die 2 Anweisungen davor sind ausgeführt und bleiben bestehen.', $message['text'] ?? null);
        $this->assertSame(1, (int) $writer->table('probe')->count());
        $entry = AuditEntry::query()->sole();
        $this->assertSame('data.import', $entry->action);
        $this->assertTrue($entry->context['failed'] ?? false);
    }

    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function transactionalDrivers(): array
    {
        return ['PostgreSQL' => [DatabaseDriver::Postgres], 'SQLite' => [DatabaseDriver::Sqlite]];
    }

    #[DataProvider('transactionalDrivers')]
    public function test_transaction_statements_of_a_dump_are_skipped_inside_the_own_transaction(DatabaseDriver $driver): void
    {
        Storage::fake(Transfer::DISK);
        [$writer, $connection, $schema] = $this->database($driver);
        $file = $this->file("BEGIN TRANSACTION;\ncreate table probe (a int);\ninsert into probe values (1), (2);\nCOMMIT;\n");

        $transfer = $this->runTransfer($connection, TransferKind::Import, TransferFormat::Sql, ['schema' => $schema], $file);

        $this->assertSame(TransferStatus::Done, $transfer->status, json_encode($transfer->error) ?: '');
        $this->assertSame(2, $transfer->result['skipped'] ?? null);
        $this->assertSame(2, $transfer->rows);
        $this->assertSame(2, (int) $writer->table('probe')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'data.import')->count());
    }

    /**
     * @return array{0: Connection, 1: DatabaseConnection, 2: string}
     */
    private function database(DatabaseDriver $driver): array
    {
        $settings = $this->scratchSettings($driver, $this->folder);

        return [app(ConnectionFactory::class)->make($settings), $this->savedConnection($settings), $this->sampleSchemaName($driver)];
    }

    private function file(string $sql): string
    {
        $file = $this->folder.'/skript.sql';
        file_put_contents($file, $sql);

        return $file;
    }
}
