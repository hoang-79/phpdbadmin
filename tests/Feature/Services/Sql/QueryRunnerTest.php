<?php

namespace Tests\Feature\Services\Sql;

use App\Connections\ConnectionFactory;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Services\Sql\QueryRunner;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\SqliteWorkerFolders;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * SQL-Editor ausführen (Q-02, Q-03, Q-09) je Treiber: mehrere Ergebnisse, Zeilengrenze, Zeitgrenze, Schreibschutz,
 * fehlendes Recht, Abbrechen — auch gegen eine Abfrage, die auf dem Server wirklich läuft.
 */
#[Group('target-databases')]
class QueryRunnerTest extends TestCase
{
    use RefreshDatabase;
    use SqliteWorkerFolders;
    use TargetDatabases;

    private string $folder;

    private Connection $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/editor-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config([
            'phpdbadmin.connections.allowed_hosts' => ['127.0.0.1'],
            'cache.default' => 'array',
        ]);
        $this->allowSqliteFolders([$this->folder]);
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
    public function test_runs_statements_in_order_with_rows_and_affected_counts(DatabaseDriver $driver): void
    {
        $connection = $this->connectionWithNumbers($driver, 5);

        $results = $this->execute($connection, ['select n, label from numbers order by n', "insert into numbers (n, label) values (6, 'sechs')", 'select count(*) as anzahl from numbers'], mayWrite: true);

        $this->assertSame(['ok', 'ok', 'ok'], array_column($results, 'status'));
        $this->assertSame(['n', 'label'], array_column($results[0]['columns'], 'name'));
        $this->assertSame([1, 'eins'], $results[0]['rows'][0]);
        $this->assertSame(1, $results[1]['affected']);
        $this->assertEquals([[6]], $results[2]['rows']);
    }

    #[DataProvider('drivers')]
    public function test_row_limit_truncates_and_the_session_stays_usable(DatabaseDriver $driver): void
    {
        $connection = $this->connectionWithNumbers($driver, 25);

        $results = $this->execute($connection, ['select n from numbers order by n', 'select 42 as antwort'], limit: 10);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertCount(10, $results[0]['rows']);
        $this->assertTrue($results[0]['truncated']);
        $this->assertEquals([[42]], $results[1]['rows']);
    }

    #[DataProvider('drivers')]
    public function test_long_statement_ends_at_the_time_limit(DatabaseDriver $driver): void
    {
        $connection = $this->connectionWithNumbers($driver, 1);
        config(['phpdbadmin.editor.timeout_seconds' => 1]);

        $start = hrtime(true);
        $results = $this->execute($connection, [$this->longQuery($driver), 'select 1']);

        $this->assertSame(['timeout', 'skipped'], array_column($results, 'status'));
        $this->assertLessThan(8000, (hrtime(true) - $start) / 1e6);
    }

    #[DataProvider('drivers')]
    public function test_writes_on_a_read_only_connection_are_refused(DatabaseDriver $driver): void
    {
        $connection = $this->connectionWithNumbers($driver, 2);
        $connection->update(['read_only' => true]);

        $results = $this->execute($connection, ['delete from numbers'], mayWrite: true);

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame(2, $this->writer->table('numbers')->count());
    }

    #[DataProvider('drivers')]
    public function test_writes_without_permission_are_not_executed_and_reads_cannot_write(DatabaseDriver $driver): void
    {
        $connection = $this->connectionWithNumbers($driver, 2);

        $refused = $this->execute($connection, ['update numbers set label = 1'], mayWrite: false);
        $this->assertSame('error', $refused[0]['status']);

        // Als lesend eingeordnet, aber mit Wirkung: der Wächter weist es trotzdem ab (nur PostgreSQL kennt das so).
        if ($driver === DatabaseDriver::Postgres) {
            $sneaky = $this->execute($connection, ['with x as (select 1) select * from x; '], mayWrite: false);
            $this->assertSame('ok', $sneaky[0]['status']);
        }

        $this->assertSame(['eins', 'zwei'], $this->writer->table('numbers')->orderBy('n')->pluck('label')->all());
    }

    #[DataProvider('drivers')]
    public function test_cancel_marker_wins_over_the_result(DatabaseDriver $driver): void
    {
        $connection = $this->connectionWithNumbers($driver, 1);
        Cache::put('sql-abbruch:lauf-1', true);

        $results = $this->execute($connection, ['select n from numbers', 'select 2'], runId: 'lauf-1');

        $this->assertSame('cancelled', $results[0]['status']);
        $this->assertSame('skipped', $results[1]['status']);
    }

    #[DataProvider('serverDrivers')]
    public function test_cancel_ends_a_query_running_on_the_server(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $connection = $this->savedConnection($settings);
        $sleep = $driver === DatabaseDriver::Postgres ? 'select pg_sleep(20)' : 'select sleep(20)';
        $session = $driver === DatabaseDriver::Postgres ? 'select pg_backend_pid()' : 'select connection_id()';
        $dsn = $driver === DatabaseDriver::Postgres
            ? sprintf('pgsql:host=%s;port=%d;dbname=%s', $settings->host, $settings->port, $settings->database)
            : sprintf('mysql:host=%s;port=%d;dbname=%s', $settings->host, $settings->port, $settings->database);

        $script = sprintf(
            '$p = new PDO(%s, %s, %s); echo $p->query(%s)->fetchColumn(), PHP_EOL; flush(); $t = microtime(true); try { $p->query(%s)->fetchAll(); } catch (Throwable) {} echo round(microtime(true) - $t, 1);',
            var_export($dsn, true), var_export((string) $settings->username, true), var_export((string) $settings->password, true), var_export($session, true), var_export($sleep, true),
        );
        $sleeper = Process::start([PHP_BINARY, '-r', $script]);

        $deadline = microtime(true) + 5;
        while (trim($sleeper->output()) === '' && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $id = (int) trim(strtok($sleeper->output(), "\n") ?: '0');
        $this->assertGreaterThan(0, $id);
        usleep(300_000);

        app(QueryRunner::class)->killQuery($connection, null, $id);
        $sleeper->wait();

        $lines = explode("\n", trim($sleeper->output()));
        $this->assertLessThan(5, (float) end($lines), 'Die Abfrage lief nach dem Abbrechen weiter');
    }

    public function test_sqlite_worker_reports_cancel_before_start(): void
    {
        $connection = $this->connectionWithNumbers(DatabaseDriver::Sqlite, 1);
        Cache::put('sql-abbruch:lauf-2', true);

        $results = $this->execute($connection, [$this->longQuery(DatabaseDriver::Sqlite)], runId: 'lauf-2');

        $this->assertSame('cancelled', $results[0]['status']);
    }

    public function test_a_run_of_another_account_cannot_be_cancelled(): void
    {
        $connection = $this->connectionWithNumbers(DatabaseDriver::Sqlite, 1);
        Cache::put('sql-lauf:fremd', ['connection' => $connection->id, 'database' => null, 'user' => $connection->owner_id + 1000, 'session' => null]);

        $this->assertFalse(app(QueryRunner::class)->cancel($connection, 'fremd', $connection->owner_id));
        $this->assertFalse(Cache::has('sql-abbruch:fremd'));

        $this->assertTrue(app(QueryRunner::class)->cancel($connection, 'eigener-start', $connection->owner_id));
        $this->assertTrue(Cache::has('sql-abbruch:eigener-start'), 'Abbrechen gleich nach dem Start setzt den Merker');
    }

    private function connectionWithNumbers(DatabaseDriver $driver, int $count): DatabaseConnection
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $this->writer = app(ConnectionFactory::class)->make($settings);
        $this->writer->getSchemaBuilder()->create('numbers', function ($table) {
            $table->integer('n')->primary();
            $table->string('label');
        });
        $labels = ['eins', 'zwei', 'drei', 'vier', 'fünf'];
        for ($n = 1; $n <= $count; $n++) {
            $this->writer->table('numbers')->insert(['n' => $n, 'label' => $labels[$n - 1] ?? 'zahl '.$n]);
        }

        return $this->savedConnection($settings);
    }

    /**
     * Eine Abfrage, die viel länger als eine Sekunde läuft — und vom Zeitlimit wirklich erfasst wird (MySQL bricht
     * SLEEP still ab, deshalb ein grosses Kreuzprodukt).
     */
    private function longQuery(DatabaseDriver $driver): string
    {
        return match ($driver) {
            DatabaseDriver::Postgres => 'select pg_sleep(5)',
            DatabaseDriver::MariaDb => 'select sleep(5)',
            DatabaseDriver::MySql => 'select count(*) from information_schema.columns a cross join information_schema.columns b cross join information_schema.columns c',
            DatabaseDriver::Sqlite => 'with recursive n(x) as (select 1 union all select x + 1 from n where x < 500000000) select count(*) from n',
        };
    }

    /**
     * @param  list<string>  $statements
     * @return list<array<string, mixed>>
     */
    private function execute(DatabaseConnection $connection, array $statements, bool $mayWrite = false, int $limit = 1000, string $runId = 'lauf'): array
    {
        return app(QueryRunner::class)->run($connection, null, $statements, $runId, $mayWrite, $limit, 1);
    }
}
