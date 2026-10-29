<?php

namespace App\Services\Transfer;

use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Models\Transfer;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use PDOException;
use Throwable;

/**
 * SQL-Datei ausführen (E-02) — Anweisung für Anweisung, jede einzeln vorbereitet (T-07), mit Fortschritt nach Bytes.
 *
 * - Probelauf: nur zerlegen und zählen, nichts ausführen (DDL lässt sich bei MySQL/MariaDB nicht zurückrollen).
 *   Er nennt die Arten der Anweisungen und warnt vor DROP, TRUNCATE und DELETE/UPDATE ohne WHERE.
 * - PostgreSQL und SQLite: alles in einer Transaktion — bei einem Fehler bleibt nichts. Eigene BEGIN/COMMIT der
 *   Datei werden übergangen. SQLite: Fremdschlüssel während des Laufs aus, am Ende geprüft.
 * - MySQL und MariaDB: ohne Transaktion (DDL schliesst sie ohnehin); bei einem Fehler bleibt, was davor lief.
 *   Was der Server nicht vorbereiten kann (Fehler 1295: USE, LOCK TABLES, CREATE TRIGGER …), läuft ohne Vorbereitung —
 *   die Verbindung lässt ohnehin nie mehrere Anweisungen in einem Aufruf zu, und der Zerleger hat sie getrennt.
 *
 * @phpstan-type Dangerous array{line: int, keyword: string}
 * @phpstan-type SqlResult array{statements: int, executed: int, skipped: int, affected: int, kinds: array<string, int>, dangerous: list<Dangerous>, dangerous_count: int, transactional: bool}
 */
final class SqlImporter
{
    private const array TRANSACTION_CONTROL = ['BEGIN', 'START', 'COMMIT', 'END', 'ROLLBACK'];

    private const int DANGEROUS_DETAILS = 50;

    public function __construct(private readonly TargetConnections $targets) {}

    /**
     * @return SqlResult
     *
     * @throws TransferCancelled
     * @throws TransferFailed
     */
    public function run(Transfer $transfer, TransferProgress $progress): array
    {
        $connection = $transfer->databaseConnection;
        $driver = $connection->driver;
        $path = Storage::disk(Transfer::DISK)->path((string) $transfer->file_path);
        $size = max(1, (int) filesize($path));
        $script = new SqlScript($path, $driver);
        $transactional = $driver === DatabaseDriver::Postgres || $driver === DatabaseDriver::Sqlite;
        $result = [
            'statements' => 0, 'executed' => 0, 'skipped' => 0, 'affected' => 0, 'kinds' => [], 'dangerous' => [],
            'dangerous_count' => 0, 'transactional' => $transactional,
        ];

        if ($transfer->dry_run) {
            foreach ($script->statements() as $statement) {
                $this->count($result, $statement);
                $progress->report($result['statements'], $script->offset() / $size);
            }

            return $result;
        }

        // Vor dem ersten Schreiben: die Verbindung muss jetzt noch beschreibbar sein (T-06).
        $this->targets->assertWritable($connection);
        $target = $this->targets->open($connection, $transfer->openDatabase());
        $this->prepare($target, $driver, (string) $transfer->option('schema'));

        if ($transactional) {
            $target->beginTransaction();
        }

        try {
            foreach ($script->statements() as $statement) {
                $keyword = $this->count($result, $statement);

                if ($transactional && in_array($keyword, self::TRANSACTION_CONTROL, true)) {
                    $result['skipped']++;

                    continue;
                }

                $result['affected'] += $this->execute($target, $driver, $statement, $result['executed'], $transactional);
                $result['executed']++;
                $progress->report($result['affected'], $script->offset() / $size);
            }

            if ($driver === DatabaseDriver::Sqlite) {
                $this->checkForeignKeys($target);
            }

            if ($transactional) {
                $target->commit();
            }
        } catch (Throwable $exception) {
            if ($transactional && $target->transactionLevel() > 0) {
                $target->rollBack();
            }

            throw $exception;
        } finally {
            if ($driver === DatabaseDriver::Sqlite) {
                $target->statement('pragma foreign_keys = on');
            }
        }

        return $result;
    }

    /**
     * Ziel-Schema für Namen ohne Schema; MySQL/MariaDB: Sitzung wieder beschreibbar.
     */
    private function prepare(Connection $target, DatabaseDriver $driver, string $schema): void
    {
        $wrapped = $target->getQueryGrammar()->wrap($schema);

        if ($driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb) {
            // Das Schema ist die Datenbank der Verbindung (ImportController öffnet sie so); die Sitzung wieder beschreibbar.
            $target->statement('set session transaction read write');
        } elseif ($driver === DatabaseDriver::Postgres) {
            $target->statement('set search_path to '.$wrapped);
        } else {
            // Innerhalb einer Transaktion wirkt das PRAGMA nicht — deshalb vorher.
            $target->statement('pragma foreign_keys = off');
        }
    }

    /**
     * @param  array{sql: string, line: int}  $statement
     *
     * @throws TransferFailed
     */
    private function execute(Connection $target, DatabaseDriver $driver, array $statement, int $done, bool $transactional): int
    {
        $message = null;

        try {
            return $target->affectingStatement($statement['sql']);
        } catch (QueryException $exception) {
            if (($driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb) && ($exception->errorInfo[1] ?? null) === 1295) {
                try {
                    // Bewusst SQL der Person (ihre Datei, Recht «schreiben»): eine Anweisung, die der Server nicht
                    // vorbereiten kann. Die Verbindung erlaubt keine Mehrfachanweisungen (T-07).
                    $target->getPdo()->exec($statement['sql']);

                    return 0;
                } catch (PDOException $retry) {
                    $message = $retry->getMessage();
                }
            }

            throw new TransferFailed(
                $transactional
                    ? __('Anweisung :number (Zeile :line) ist fehlgeschlagen. Nichts wurde geändert.', locale: 'de')
                    : __('Anweisung :number (Zeile :line) ist fehlgeschlagen. Die :done Anweisungen davor sind ausgeführt und bleiben bestehen.', locale: 'de'),
                ['number' => $done + 1, 'line' => $statement['line'], 'done' => $done],
                mb_strimwidth($message ?? $exception->getPrevious()?->getMessage() ?? $exception->getMessage(), 0, 500, '…'),
            );
        }
    }

    /**
     * SQLite prüft Fremdschlüssel bei abgeschalteter Prüfung nicht — am Ende einmal für alles.
     *
     * @throws TransferFailed
     */
    private function checkForeignKeys(Connection $target): void
    {
        $violations = $target->select('pragma foreign_key_check');

        if ($violations !== []) {
            $first = (array) $violations[0];

            throw new TransferFailed(
                __(':count Zeilen verweisen auf Datensätze, die es nicht gibt (zuerst in «:table»). Nichts wurde geändert.', locale: 'de'),
                ['count' => count($violations), 'table' => (string) ($first['table'] ?? '')],
            );
        }
    }

    /**
     * Zählt die Anweisung nach Art und merkt sich gefährliche.
     *
     * @param  SqlResult  $result
     * @param  array{sql: string, line: int}  $statement
     */
    private function count(array &$result, array $statement): string
    {
        $keyword = SqlScript::keyword($statement['sql']);
        $result['statements']++;
        $result['kinds'][$keyword] = ($result['kinds'][$keyword] ?? 0) + 1;

        $dangerous = in_array($keyword, ['DROP', 'TRUNCATE'], true)
            || (in_array($keyword, ['DELETE', 'UPDATE'], true) && preg_match('/\bwhere\b/i', $statement['sql']) !== 1);

        if ($dangerous) {
            $result['dangerous_count']++;

            if (count($result['dangerous']) < self::DANGEROUS_DETAILS) {
                $result['dangerous'][] = ['line' => $statement['line'], 'keyword' => $keyword];
            }
        }

        return $keyword;
    }
}
