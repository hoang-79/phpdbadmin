<?php

namespace App\Services\Sql;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Services\Data\Cells;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Process;
use PDO;
use Pdo\Mysql;
use PDOException;
use Throwable;

use function Illuminate\Support\php_binary;

/**
 * Führt Anweisungen aus dem SQL-Editor aus (Q-02, Q-03, Q-09, ADR-002): eine nach der anderen, mit Zeitgrenze und
 * Zeilengrenze je Ergebnis, schreibend nur mit Recht auf beschreibbaren Verbindungen. Abbrechen ist eine zweite
 * Anfrage; ein Merker im Cache entscheidet über «abgebrochen», nicht der Fehlercode — MySQL kehrt nach KILL QUERY
 * auch ohne Fehler zurück (F-2). SQLite lässt sich in PDO nicht unterbrechen und läuft deshalb in einem
 * Arbeitsprozess, der bei Zeitgrenze oder Abbruch beendet wird.
 *
 * @phpstan-import-type Cell from Cells
 *
 * @phpstan-type Result array{sql: string, status: 'ok'|'error'|'cancelled'|'timeout'|'skipped', columns: list<array{name: string, type: string|null}>, rows: list<list<Cell>>, truncated: bool, affected: int|null, durationMs: float, message: string|null}
 */
final class QueryRunner
{
    private const string RUN_KEY = 'sql-lauf:';

    private const string CANCEL_KEY = 'sql-abbruch:';

    public function __construct(
        private readonly TargetConnections $targets,
        private readonly ConnectionFactory $factory,
        private readonly StatementClassifier $classifier,
        private readonly Cache $cache,
    ) {}

    /**
     * @param  list<string>  $statements
     * @return list<Result>
     */
    public function run(DatabaseConnection $connection, ?string $database, array $statements, string $runId, bool $mayWrite, int $limit, int $userId, ?string $searchPath = null): array
    {
        $timeoutMs = (int) config('phpdbadmin.editor.timeout_seconds', 30) * 1000;
        $mayWrite = $mayWrite && ! $connection->read_only;

        if ($connection->driver === DatabaseDriver::Sqlite) {
            return $this->runInWorker($connection, $statements, $runId, $mayWrite, $limit, $timeoutMs);
        }

        $target = $this->targets->open($connection, $database);
        $dialect = $connection->driver->dialect();
        $dialect->setStatementTimeout($target, $timeoutMs);

        if ($searchPath !== null && $connection->driver === DatabaseDriver::Postgres) {
            // Unqualifizierte Namen im gewählten Schema suchen; gequotet, weil der Name von aussen kommt.
            $target->statement('set search_path to "'.str_replace('"', '""', $searchPath).'", public');
        }
        $sessionId = (string) $target->selectOne($connection->driver === DatabaseDriver::Postgres ? 'select pg_backend_pid() as id' : 'select connection_id() as id')?->id;

        $this->cache->put(self::RUN_KEY.$runId, ['connection' => $connection->id, 'database' => $database, 'user' => $userId, 'session' => $sessionId], now()->addMinutes(30));

        $results = [];
        $stopped = false;

        try {
            foreach ($statements as $sql) {
                if ($stopped) {
                    $results[] = $this->result($sql, 'skipped');

                    continue;
                }

                [$result, $timedOut] = $this->runOnServer($connection, $database, $target, (int) $sessionId, $sql, $mayWrite, $limit);

                if ($this->cache->has(self::CANCEL_KEY.$runId)) {
                    $result = $this->result($sql, 'cancelled', $result['durationMs'], $this->cancelledText());
                } elseif ($result['status'] === 'error' && $timedOut) {
                    $result = $this->result($sql, 'timeout', $result['durationMs'], $this->timeoutText($timeoutMs));
                }

                $results[] = $result;
                $stopped = $result['status'] !== 'ok';
            }
        } finally {
            $this->cache->forget(self::RUN_KEY.$runId);
            $this->cache->forget(self::CANCEL_KEY.$runId);
        }

        return $results;
    }

    /**
     * Abbrechen aus einer zweiten Anfrage: Merker setzen und die laufende Anweisung auf dem Server beenden
     * (MySQL/MariaDB `KILL QUERY`, PostgreSQL `pg_cancel_backend`). SQLite beendet der wartende Lauf selbst.
     */
    public function cancel(DatabaseConnection $connection, string $runId, int $userId): bool
    {
        $this->cache->put(self::CANCEL_KEY.$runId, true, now()->addMinutes(30));
        $run = $this->cache->get(self::RUN_KEY.$runId);

        if (! is_array($run) || $run['connection'] !== $connection->id || $run['user'] !== $userId || ! ctype_digit((string) $run['session'])) {
            return $connection->driver === DatabaseDriver::Sqlite;
        }

        $this->killQuery($connection, $run['database'], (int) $run['session']);

        return true;
    }

    /**
     * Beendet die laufende Anweisung einer Sitzung über eine eigene Verbindung.
     */
    public function killQuery(DatabaseConnection $connection, ?string $database, int $session): void
    {
        try {
            $this->targets->separate($connection, function (Connection $killer) use ($connection, $session): void {
                $connection->driver === DatabaseDriver::Postgres
                    ? $killer->select('select pg_cancel_backend(?)', [$session])
                    : $killer->statement('kill query '.$session);
            }, $database);
        } catch (Throwable) {
            // Die Anweisung war schon fertig oder die Sitzung ist weg.
        }
    }

    /**
     * @return array{0: Result, 1: bool} Ergebnis und ob die Zeitgrenze zugeschlagen hat
     */
    private function runOnServer(DatabaseConnection $connection, ?string $database, Connection $target, int $session, string $sql, bool $mayWrite, int $limit): array
    {
        $dialect = $connection->driver->dialect();
        $isWrite = $this->classifier->kind($sql) === StatementKind::Write;
        $start = hrtime(true);

        if ($isWrite && ! $mayWrite) {
            return [$this->result($sql, 'error', message: $this->notAllowedText()), false];
        }

        try {
            $stopRest = fn () => $this->killQuery($connection, $database, $session);
            $read = fn (): array => $this->fetch($connection->driver, $target, $sql, $limit, ! $isWrite, $stopRest);

            if ($isWrite && in_array($connection->driver, [DatabaseDriver::MySql, DatabaseDriver::MariaDb], true)) {
                // Der Wächter setzt die Sitzung bei jeder lesenden Anweisung auf «read only» — für diese zurück.
                $target->statement('set session transaction read write');
            }

            [$columns, $rows, $truncated, $affected] = $isWrite ? $read() : $dialect->runReadOnly($target, $read);

            return [[
                'sql' => $sql,
                'status' => 'ok',
                'columns' => $columns,
                'rows' => $rows,
                'truncated' => $truncated,
                'affected' => $affected,
                'durationMs' => round((hrtime(true) - $start) / 1e6, 1),
                'message' => null,
            ], false];
        } catch (Throwable $exception) {
            $message = $dialect->isReadOnlyViolation($exception) ? $this->readOnlyText() : $exception->getMessage();

            return [$this->result($sql, 'error', round((hrtime(true) - $start) / 1e6, 1), $message), $dialect->isTimeout($exception)];
        } finally {
            $this->restoreBuffering($target);
        }
    }

    /**
     * Eine Anweisung ausführen und höchstens `$limit` Zeilen lesen. MySQL/MariaDB ungepuffert (der Rest wird mit
     * KILL QUERY verworfen), PostgreSQL über einen Server-Cursor, damit nie das ganze Ergebnis im Speicher landet.
     *
     * @return array{0: list<array{name: string, type: string|null}>, 1: list<list<Cell>>, 2: bool, 3: int|null}
     */
    private function fetch(DatabaseDriver $driver, Connection $target, string $sql, int $limit, bool $isRead, Closure $stopRest): array
    {
        $pdo = $target->getPdo();

        if ($driver === DatabaseDriver::Postgres && $isRead && preg_match('/^\s*(\(\s*)*(SELECT|WITH|VALUES|TABLE)\b/i', $this->withoutLeadingComments($sql)) === 1) {
            $pdo->exec('declare phpdbadmin_ergebnis no scroll cursor for '.$sql);

            try {
                $statement = $pdo->query('fetch forward '.($limit + 1).' from phpdbadmin_ergebnis');

                return ResultReader::read($statement, $limit, null);
            } finally {
                try {
                    $pdo->exec('close phpdbadmin_ergebnis');
                } catch (PDOException) {
                    // Nach einem Fehler ist die Transaktion abgebrochen; der Cursor geht mit dem Rollback.
                }
            }
        }

        if ($pdo instanceof Mysql) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        $statement = $pdo->query($sql);

        return ResultReader::read($statement, $limit, $pdo instanceof Mysql ? $stopRest : null);
    }

    private function restoreBuffering(Connection $target): void
    {
        $pdo = $target->getRawPdo();

        if ($pdo instanceof Mysql) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /**
     * SQLite im Arbeitsprozess (Q-09): Zeitgrenze und Abbruch beenden den Prozess; fertige Ergebnisse davor bleiben.
     *
     * @param  list<string>  $statements
     * @return list<Result>
     */
    private function runInWorker(DatabaseConnection $connection, array $statements, string $runId, bool $mayWrite, int $limit, int $timeoutMs): array
    {
        $path = $this->factory->address(ConnectionSettings::fromConnection($connection, null));
        $input = json_encode([
            'path' => $path,
            'folders' => config('phpdbadmin.connections.sqlite_paths'),
            'readOnly' => ! $mayWrite,
            'limit' => $limit,
            'locale' => app()->getLocale(),
            'statements' => array_map(fn (string $sql): array => ['sql' => $sql, 'write' => $this->classifier->kind($sql) === StatementKind::Write], $statements),
        ], JSON_THROW_ON_ERROR);

        $process = Process::path(base_path())->input($input)->forever()->start([$this->phpBinary(), 'artisan', 'phpdbadmin:sqlite-worker']);
        $started = hrtime(true);
        $stoppedAs = null;

        while ($process->running()) {
            if ($this->cache->has(self::CANCEL_KEY.$runId)) {
                $stoppedAs = 'cancelled';
            } elseif ((hrtime(true) - $started) / 1e6 > $timeoutMs) {
                $stoppedAs = 'timeout';
            }

            if ($stoppedAs !== null) {
                $process->signal(SIGKILL);

                break;
            }

            usleep(10_000);
        }

        $this->cache->forget(self::CANCEL_KEY.$runId);
        $process->wait();

        $results = [];
        foreach (preg_split('/\R/', trim($process->output())) ?: [] as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['sql'], $decoded['status'])) {
                /** @var Result $decoded */
                $results[] = $decoded;
            }
        }

        $finished = count($results);
        $elapsed = round((hrtime(true) - $started) / 1e6, 1) - array_sum(array_column($results, 'durationMs'));

        foreach (array_slice($statements, $finished) as $offset => $sql) {
            $results[] = match (true) {
                $offset === 0 && $stoppedAs === 'cancelled' => $this->result($sql, 'cancelled', $elapsed, $this->cancelledText()),
                $offset === 0 && $stoppedAs === 'timeout' => $this->result($sql, 'timeout', $elapsed, $this->timeoutText($timeoutMs)),
                $offset === 0 && ($results[$finished - 1]['status'] ?? 'ok') === 'ok' => $this->result($sql, 'error', $elapsed, trim($process->errorOutput()) ?: $this->workerFailedText()),
                default => $this->result($sql, 'skipped'),
            };
        }

        return $results;
    }

    /**
     * PHP für die Kommandozeile — unter PHP-FPM zeigt PHP_BINARY auf FPM, nicht auf die CLI.
     */
    private function phpBinary(): string
    {
        $configured = config('phpdbadmin.editor.php_binary');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }

        foreach ([(string) preg_replace('/-fpm$/', '', PHP_BINARY), dirname(PHP_BINARY).'/php', php_binary()] as $candidate) {
            if ($candidate !== PHP_BINARY && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    private function withoutLeadingComments(string $sql): string
    {
        return (string) preg_replace('~^(\s*(--[^\n]*\n|/\*.*?\*/))*~s', '', $sql);
    }

    /**
     * @param  'ok'|'error'|'cancelled'|'timeout'|'skipped'  $status
     * @return Result
     */
    private function result(string $sql, string $status, float $durationMs = 0.0, ?string $message = null): array
    {
        return ['sql' => $sql, 'status' => $status, 'columns' => [], 'rows' => [], 'truncated' => false, 'affected' => null, 'durationMs' => $durationMs, 'message' => $message];
    }

    private function cancelledText(): string
    {
        return __('Abgebrochen.');
    }

    private function timeoutText(int $timeoutMs): string
    {
        return __('Abgebrochen nach :seconds s (Zeitgrenze).', ['seconds' => intdiv($timeoutMs, 1000)]);
    }

    private function notAllowedText(): string
    {
        return __('Diese Verbindung ist schreibgeschützt oder Ihr Konto darf hier nicht schreiben — die Anweisung wurde nicht ausgeführt.');
    }

    private function readOnlyText(): string
    {
        return __('Schreibschutz: Diese Anweisung würde etwas ändern und wurde abgewiesen.');
    }

    private function workerFailedText(): string
    {
        return __('Der Arbeitsprozess für SQLite ist unerwartet beendet worden.');
    }
}
