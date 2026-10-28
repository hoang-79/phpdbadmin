<?php

namespace Tests\Feature\Feasibility;

use Illuminate\Database\QueryException;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * F-2 Verbindungen: Zeitlimit je Anweisung, Abbrechen laufender Abfragen, Zeitzone der Sitzung
 * und verständliche Fehler beim Verbinden — je Treiber.
 */
#[Group('feasibility')]
class ConnectionProbeTest extends ProbeTestCase
{
    #[DataProvider('serverDrivers')]
    public function test_statement_timeout_stops_a_long_query_within_two_seconds(string $driver): void
    {
        $connection = $this->connection($driver);

        [$sleep, $setup] = match ($driver) {
            // MySQL: max_execution_time gilt nur für SELECT; SLEEP wird abgebrochen und liefert 1.
            'mysql' => ['SELECT SLEEP(5) AS result', 'SET SESSION max_execution_time = 1000'],
            'mariadb' => ['SELECT SLEEP(5) AS result', 'SET SESSION max_statement_time = 1'],
            'pgsql' => ['SELECT pg_sleep(5) AS result', 'SET statement_timeout = 1000'],
        };
        $connection->statement($setup);

        $error = null;
        $result = null;
        [, $elapsedMs] = $this->measure(function () use ($connection, $sleep, &$error, &$result) {
            try {
                $result = $connection->selectOne($sleep)?->result;
            } catch (QueryException $exception) {
                $error = $exception->errorInfo[0] ?? $exception->getCode();
            }
        });

        $this->recordFinding('f2-zeitlimit', $driver, ['dauer_ms' => $elapsedMs, 'fehler_sqlstate' => $error, 'ergebnis' => $result]);

        $this->assertLessThan(2000, $elapsedMs);
    }

    public function test_sqlite_has_no_statement_timeout_and_runs_a_heavy_query_to_the_end(): void
    {
        $connection = $this->connection('sqlite');

        [$count, $elapsedMs] = $this->measure(fn () => $connection->selectOne(
            'WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 3000000) SELECT count(*) AS c FROM n'
        )->c);

        // Kein Zeitlimit je Anweisung in PDO-SQLite (kein Fortschritts-Handler, kein Interrupt): Grenze nur über
        // die PHP-Laufzeit des Prozesses oder einen eigenen Arbeitsprozess.
        $this->recordFinding('f2-zeitlimit', 'sqlite', ['dauer_ms' => $elapsedMs, 'zeilen' => $count, 'zeitlimit' => 'nicht verfügbar']);

        $this->assertSame(3000000, (int) $count);
    }

    #[DataProvider('serverDrivers')]
    public function test_running_query_is_cancelled_from_a_second_connection(string $driver): void
    {
        $config = $this->config($driver);
        $this->connection($driver);
        $monitor = $this->connection($driver);

        $child = Process::timeout(30)->start([PHP_BINARY, '-r', $this->sleepingClientScript($driver, $config)]);

        $sessionId = $this->waitForSessionId($child);
        usleep(300_000);

        $start = hrtime(true);
        match ($driver) {
            'mysql', 'mariadb' => $monitor->statement('KILL QUERY '.(int) $sessionId),
            'pgsql' => $monitor->select('SELECT pg_cancel_backend(?)', [(int) $sessionId]),
        };
        $result = $child->wait();
        $elapsedMs = round((hrtime(true) - $start) / 1e6, 1);

        $this->recordFinding('f2-abbrechen', $driver, [
            'bis_ende_ms' => $elapsedMs,
            'ausgabe' => trim($result->output()),
        ]);

        $this->assertLessThan(3000, $elapsedMs, 'Die Abfrage lief nach dem Abbrechen weiter');
        $this->assertStringContainsString('ENDE', $result->output());
    }

    #[DataProvider('serverDrivers')]
    public function test_session_timezone_from_connection_config_is_utc(string $driver): void
    {
        $connection = $this->connection($driver, ['timezone' => $driver === 'pgsql' ? 'UTC' : '+00:00']);

        $zone = match ($driver) {
            'mysql', 'mariadb' => $connection->selectOne('SELECT @@session.time_zone AS zone')->zone,
            'pgsql' => $connection->selectOne('SHOW TIME ZONE')->TimeZone,
        };

        $this->assertContains($zone, ['+00:00', 'UTC']);
    }

    #[DataProvider('serverDrivers')]
    public function test_connection_errors_are_distinguishable_and_never_contain_the_password(string $driver): void
    {
        $cases = [
            'host_unreachable' => ['host' => '127.0.0.1', 'port' => 1],
            'wrong_password' => ['username' => 'probe_unknown_user', 'password' => 'Geheim-Probe-4711'],
            'database_missing' => ['database' => 'phpdbadmin_gibt_es_nicht'],
        ];

        $findings = [];
        foreach ($cases as $case => $overrides) {
            $config = array_replace($this->config($driver), $overrides);
            $this->connection($driver);

            try {
                DB::build($config)->getPdo();
                $findings[$case] = 'verbunden (unerwartet)';
            } catch (Throwable $exception) {
                $message = $exception->getMessage();
                $this->assertStringNotContainsString('Geheim-Probe-4711', $message);
                $findings[$case] = [
                    'sqlstate' => $exception instanceof \PDOException || $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null,
                    'code' => $exception->getCode(),
                    'meldung' => mb_substr(preg_replace('/\s+/', ' ', $message), 0, 160),
                ];
            }
        }

        $this->recordFinding('f2-verbindungsfehler', $driver, $findings);

        $this->assertNotSame($findings['host_unreachable'], $findings['wrong_password']);
        $this->assertNotSame($findings['wrong_password'], $findings['database_missing']);
    }

    /**
     * Kindprozess: verbindet sich, meldet seine Sitzungskennung und schläft 20 Sekunden.
     *
     * @param  array<string, mixed>  $config
     */
    private function sleepingClientScript(string $driver, array $config): string
    {
        $dsn = $driver === 'pgsql'
            ? "pgsql:host={$config['host']};port={$config['port']};dbname=".self::PROBE_DATABASE
            : "mysql:host={$config['host']};port={$config['port']};dbname=".self::PROBE_DATABASE;
        [$idSql, $sleepSql] = $driver === 'pgsql'
            ? ['SELECT pg_backend_pid()', 'SELECT pg_sleep(20)']
            : ['SELECT CONNECTION_ID()', 'SELECT SLEEP(20)'];

        return sprintf(
            '$pdo = new PDO(%s, %s, %s, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            .'echo "ID:", $pdo->query(%s)->fetchColumn(), PHP_EOL; flush();'
            .'try { $r = $pdo->query(%s)->fetchColumn(); echo "ERGEBNIS:", var_export($r, true), PHP_EOL; }'
            .'catch (Throwable $e) { echo "FEHLER:", $e->getCode(), PHP_EOL; }'
            .'echo "ENDE", PHP_EOL;',
            var_export($dsn, true),
            var_export($config['username'], true),
            var_export($config['password'], true),
            var_export($idSql, true),
            var_export($sleepSql, true),
        );
    }

    private function waitForSessionId(InvokedProcess $child): string
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (preg_match('/ID:(\d+)/', $child->output(), $match)) {
                return $match[1];
            }
            usleep(50_000);
        }

        $this->fail('Kindprozess meldete keine Sitzungskennung: '.$child->errorOutput());
    }
}
