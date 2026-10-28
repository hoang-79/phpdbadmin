<?php

namespace App\Console\Commands;

use App\Connections\ConnectionFactory;
use App\Connections\ConnectionSettings;
use App\Enums\DatabaseDriver;
use App\Services\Sql\ResultReader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Arbeitsprozess für SQLite-Abfragen aus dem SQL-Editor (Q-09): SQLite kennt kein Zeitlimit je Anweisung und lässt
 * sich in PDO nicht unterbrechen — dieser Prozess wird bei Zeitgrenze oder Abbruch von aussen beendet. Liest den
 * Auftrag als JSON von der Standardeingabe und schreibt je fertiger Anweisung eine JSON-Zeile. Nur vom QueryRunner
 * aufgerufen; der Pfad ist dort schon gegen die erlaubten Ordner geprüft und wird hier noch einmal geprüft.
 */
#[Signature('phpdbadmin:sqlite-worker')]
#[Description('Interner Arbeitsprozess für SQLite-Abfragen aus dem SQL-Editor')]
#[Hidden]
class SqliteWorkerCommand extends Command
{
    public function handle(ConnectionFactory $factory): int
    {
        /** @var array{path: string, folders: list<string>, readOnly: bool, limit: int, locale?: string, statements: list<array{sql: string, write: bool}>} $job */
        $job = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

        config(['phpdbadmin.connections.sqlite_paths' => $job['folders']]);
        app()->setLocale($job['locale'] ?? config('app.locale'));

        $connection = $factory->make(new ConnectionSettings(DatabaseDriver::Sqlite, sqlitePath: $job['path']), readOnly: $job['readOnly']);
        $pdo = $connection->getPdo();

        foreach ($job['statements'] as $statement) {
            $start = hrtime(true);

            try {
                if ($statement['write'] && $job['readOnly']) {
                    throw new \RuntimeException($this->notAllowedText());
                }

                [$columns, $rows, $truncated, $affected] = ResultReader::read($pdo->query($statement['sql']), $job['limit'], null);
                $result = ['sql' => $statement['sql'], 'status' => 'ok', 'columns' => $columns, 'rows' => $rows, 'truncated' => $truncated, 'affected' => $affected, 'message' => null];
            } catch (Throwable $exception) {
                $result = ['sql' => $statement['sql'], 'status' => 'error', 'columns' => [], 'rows' => [], 'truncated' => false, 'affected' => null, 'message' => $exception->getMessage()];
            }

            $result['durationMs'] = round((hrtime(true) - $start) / 1e6, 1);
            $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));

            if ($result['status'] !== 'ok') {
                break;
            }
        }

        return self::SUCCESS;
    }

    private function notAllowedText(): string
    {
        return __('Diese Verbindung ist schreibgeschützt oder Ihr Konto darf hier nicht schreiben — die Anweisung wurde nicht ausgeführt.');
    }
}
