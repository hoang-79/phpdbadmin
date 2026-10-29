<?php

namespace Tests\Feature\Services\Transfer;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Services\Transfer\TableReader;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\Concerns\TransferSamples;
use Tests\TestCase;

/**
 * Lesen in Paketen (F-8, ADR-007): über einen zusammengesetzten Schlüssel ohne Lücke und ohne Doppel über die
 * Paketgrenze; ohne Schlüssel über einen Cursor — auch gleiche Zeilen kommen alle.
 */
#[Group('target-databases')]
class TableReaderTest extends TestCase
{
    use LazilyRefreshDatabase;
    use TargetDatabases;
    use TransferSamples;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/lesen-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'], 'phpdbadmin.connections.sqlite_paths' => [$this->folder]]);
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
    public function test_composite_key_pages_cover_every_row_once_in_key_order(DatabaseDriver $driver): void
    {
        [$connection, $schema] = $this->samples($driver);

        $rows = $this->read($connection, $driver, $schema, 'positionen', ['bestellung_id', 'nr', 'artikel'], ['bestellung_id', 'nr']);

        $this->assertSame([[1, 1], [1, 2], [2, 1], [3, 2], [3, 10]], array_map(fn (array $row): array => [(int) $row['bestellung_id'], (int) $row['nr']], $rows));
    }

    #[DataProvider('drivers')]
    public function test_table_without_key_streams_all_rows_including_duplicates(DatabaseDriver $driver): void
    {
        [$connection, $schema] = $this->samples($driver);

        $rows = $this->read($connection, $driver, $schema, 'protokoll', ['eintrag', 'zahl'], []);
        $entries = array_map(fn (array $row): string => $row['eintrag'].'|'.$row['zahl'], $rows);
        sort($entries);

        $this->assertSame(['doppelt|1', 'doppelt|1', 'zuletzt|-5', '|'], $entries);
        // Danach ist die Verbindung frei für die nächste Abfrage (MySQL: ungepuffertes Ergebnis geschlossen).
        $this->assertSame(10, (int) $connection->table('kunden')->count());
    }

    /**
     * @return array{0: Connection, 1: string}
     */
    private function samples(DatabaseDriver $driver): array
    {
        $connection = app(ConnectionFactory::class)->make($this->scratchSettings($driver, $this->folder));
        $this->createTransferSamples($connection, $driver);

        return [$connection, $this->sampleSchemaName($driver)];
    }

    /**
     * Wie der Export: im Schreibschutz-Wächter, hier mit Paketen zu zwei Zeilen.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $primaryKey
     * @return list<array<string, mixed>>
     */
    private function read(Connection $connection, DatabaseDriver $driver, string $schema, string $table, array $columns, array $primaryKey): array
    {
        return $driver->dialect()->runReadOnly($connection, fn (): array => iterator_to_array(
            app(TableReader::class)->rows($connection, $driver, $schema, $table, $columns, $primaryKey, chunk: 2),
            false,
        ));
    }
}
