<?php

namespace Tests\Feature\Feasibility;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * F-2 Verbindungen: Abbrechen laufender Abfragen aus einer zweiten Verbindung — bleibt Probe bis zum SQL-Editor
 * (Q-02). Zeitlimit, Zeitzone und Verbindungsfehler sind seit TB 00 Vertragstests (tests/Feature/Dialects,
 * tests/Feature/Connections).
 */
#[Group('feasibility')]
class ConnectionProbeTest extends ProbeTestCase
{
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
