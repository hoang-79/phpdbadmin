<?php

namespace App\Services\Transfer;

use App\Connections\ConnectionNotAllowed;
use App\Connections\PasswordRequired;
use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Jobs\RunExport;
use App\Jobs\RunImport;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Audit\AuditLog;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Startet und führt Aufträge aus (B-07). Im Hintergrund über die Warteschlange, wenn ein Worker da ist und die
 * Verbindung ihr Passwort speichert — der Auftrag trägt nur seine Kennung, nie ein Passwort. Sonst gleich in der
 * Anfrage (dort liegt das Passwort «nur für diese Sitzung»), aber nur bis zu den Grenzen aus
 * `phpdbadmin.transfers` — darüber eine klare Meldung statt einer Anfrage, die nach Minuten abbricht.
 *
 * @phpstan-import-type TransferMessage from Transfer
 */
final class TransferRunner
{
    public function __construct(
        private readonly TargetConnections $targets,
        private readonly Exporter $exporter,
        private readonly CsvImporter $csv,
        private readonly SqlImporter $sql,
        private readonly Statistics $statistics,
        private readonly AuditLog $audit,
    ) {}

    public function hasWorker(): bool
    {
        return (bool) config('phpdbadmin.transfers.background', true) && config('queue.default') !== 'sync';
    }

    /** Kann ein Worker den Auftrag übernehmen? Ohne gespeichertes Passwort nicht — er hätte keines. */
    public function runsInBackground(DatabaseConnection $connection): bool
    {
        return $this->hasWorker() && ! $this->passwordOnlyInSession($connection);
    }

    public function passwordOnlyInSession(DatabaseConnection $connection): bool
    {
        return $connection->driver->isServer() && ! $connection->store_password;
    }

    /**
     * Passt der Auftrag in eine Anfrage? null, wenn ja oder wenn er ohnehin im Hintergrund läuft — sonst die Erklärung.
     *
     * @return TransferMessage|null
     */
    public function limitProblem(DatabaseConnection $connection, ?int $rows = null, ?int $bytes = null): ?array
    {
        if ($this->runsInBackground($connection)) {
            return null;
        }

        $maxRows = (int) config('phpdbadmin.transfers.sync_max_rows', 100000);
        $maxBytes = (int) config('phpdbadmin.transfers.sync_max_bytes', 20 * 1024 * 1024);
        $tooMany = $rows !== null && $rows > $maxRows;
        $tooLarge = $bytes !== null && $bytes > $maxBytes;

        if (! $tooMany && ! $tooLarge) {
            return null;
        }

        $replace = $tooMany
            ? ['amount' => number_format($rows, 0, '.', '’'), 'limit' => number_format($maxRows, 0, '.', '’')]
            : ['amount' => $this->megabytes((int) $bytes), 'limit' => $this->megabytes($maxBytes)];

        return ['text' => match (true) {
            $this->passwordOnlyInSession($connection) && $tooMany => __('Diese Verbindung speichert ihr Passwort nicht, deshalb läuft der Export hier in der Anfrage — und die schafft höchstens :limit Zeilen. Die Auswahl hat etwa :amount. Speichern Sie das Passwort in der Verbindung, dann läuft der Export im Hintergrund.', locale: 'de'),
            $this->passwordOnlyInSession($connection) => __('Diese Verbindung speichert ihr Passwort nicht, deshalb läuft der Import hier in der Anfrage — und die schafft höchstens :limit MB. Die Datei hat :amount MB. Speichern Sie das Passwort in der Verbindung, dann läuft der Import im Hintergrund.', locale: 'de'),
            $tooMany => __('Ohne Hintergrund-Worker exportiert phpdbadmin höchstens :limit Zeilen in einer Anfrage; die Auswahl hat etwa :amount. Richten Sie einen Worker ein (php artisan queue:work) oder wählen Sie weniger.', locale: 'de'),
            default => __('Ohne Hintergrund-Worker importiert phpdbadmin höchstens :limit MB in einer Anfrage; die Datei hat :amount MB. Richten Sie einen Worker ein (php artisan queue:work) oder teilen Sie die Datei.', locale: 'de'),
        }, 'replace' => $replace];
    }

    /**
     * In die Warteschlange stellen oder gleich ausführen.
     */
    public function start(Transfer $transfer): void
    {
        $background = $this->runsInBackground($transfer->databaseConnection);

        $transfer->forceFill([
            'status' => TransferStatus::Queued,
            'in_background' => $background,
            'progress' => 0,
            'rows' => 0,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ])->save();

        if ($background && $transfer->kind === TransferKind::Export) {
            RunExport::dispatch($transfer->id);

            return;
        }

        if ($background) {
            RunImport::dispatch($transfer->id);

            return;
        }

        // Ohne Worker darf die Anfrage länger dauern als üblich — die Grenzen oben halten sie kurz genug. Ohne Limit
        // (Konsole) bleibt es ohne.
        if ((int) ini_get('max_execution_time') > 0) {
            set_time_limit(300);
        }

        $this->execute($transfer);
    }

    /**
     * Den Auftrag ausführen — einmal: Wer ihn zuerst von «wartet» auf «läuft» setzt, führt ihn aus.
     */
    public function execute(Transfer $transfer): void
    {
        $claimed = Transfer::query()
            ->whereKey($transfer->id)
            ->where('status', TransferStatus::Queued->value)
            ->update(['status' => TransferStatus::Running->value, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $transfer->refresh();
        $connection = $transfer->databaseConnection;
        $dialect = $connection->driver->dialect();
        $target = null;

        try {
            $target = $this->targets->open($connection, $transfer->openDatabase());
            $dialect->setStatementTimeout($target, (int) config('phpdbadmin.transfers.statement_timeout_ms', 600000));
            $progress = new TransferProgress($transfer);

            $result = match (true) {
                $transfer->kind === TransferKind::Export => $this->exporter->run($transfer, $progress),
                $transfer->format === TransferFormat::Csv => $this->csv->run($transfer, $progress),
                default => $this->sql->run($transfer, $progress),
            };

            $this->finish($transfer, $result);
        } catch (TransferCancelled) {
            $this->discardExport($transfer);
            $transfer->forceFill(['status' => TransferStatus::Cancelled, 'finished_at' => now()])->save();
        } catch (Throwable $exception) {
            $this->discardExport($transfer);
            $transfer->forceFill(['status' => TransferStatus::Failed, 'finished_at' => now(), 'error' => $this->describe($exception)])->save();
            $this->auditPartialSqlImport($transfer, $exception);

            if (! $this->isExpected($exception)) {
                report($exception);
            }
        } finally {
            if ($target !== null) {
                try {
                    $dialect->setStatementTimeout($target, (int) config('phpdbadmin.connections.statement_timeout_ms', 15000));
                } catch (Throwable) {
                    // Verbindung schon weg — der nächste Aufbau setzt das Zeitlimit ohnehin neu.
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(Transfer $transfer, array $result): void
    {
        $isImport = $transfer->kind === TransferKind::Import;
        $isCsv = $transfer->format === TransferFormat::Csv;
        $imported = (int) ($result['imported'] ?? 0);
        $abandoned = $isImport && $isCsv && ! $transfer->dry_run && $transfer->option('on_error') === 'abort' && (int) ($result['error_count'] ?? 0) > 0;
        $analyzed = false;

        if ($isImport && $isCsv && ! $transfer->dry_run && $imported > 0 && $transfer->flag('analyze', true)) {
            $this->statistics->refresh($transfer->databaseConnection, (string) $transfer->option('schema'), (string) $transfer->option('table'), $transfer->openDatabase());
            $analyzed = true;
        }

        $transfer->forceFill([
            'status' => $abandoned ? TransferStatus::Failed : TransferStatus::Done,
            'progress' => 100,
            'rows' => match (true) {
                ! $isImport => $transfer->rows,
                $isCsv => $transfer->dry_run ? (int) ($result['ready'] ?? 0) : $imported,
                default => (int) ($result['affected'] ?? 0),
            },
            'result' => [...$result, 'analyzed' => $analyzed],
            'error' => $abandoned
                ? ['text' => __('Nichts importiert: :count Zeilen mit Fehlern.', locale: 'de'), 'replace' => ['count' => (int) $result['error_count']]]
                : null,
            'finished_at' => now(),
        ])->save();

        if ($isImport && ! $transfer->dry_run && ! $abandoned) {
            $this->audit->record('data.import', $transfer->user, $transfer->target, [
                'connection' => $transfer->connection_id,
                'format' => $transfer->format->value,
                'rows' => $transfer->rows,
                'skipped' => (int) ($result['error_count'] ?? 0),
                'statements' => $result['executed'] ?? null,
                'analyzed' => $analyzed,
                'transfer' => $transfer->id,
            ], connection: $transfer->databaseConnection, affectedRows: $transfer->rows);
        }
    }

    /**
     * MySQL und MariaDB rollen eine SQL-Datei nicht zurück: Was vor dem Fehler lief, ist geschrieben und gehört ins
     * Protokoll.
     */
    private function auditPartialSqlImport(Transfer $transfer, Throwable $exception): void
    {
        $done = $exception instanceof TransferFailed ? (int) ($exception->replace['done'] ?? 0) : 0;
        $driver = $transfer->databaseConnection->driver;
        $keepsPartialWork = $driver === DatabaseDriver::MySql || $driver === DatabaseDriver::MariaDb;

        if ($keepsPartialWork && $transfer->kind === TransferKind::Import && $transfer->format === TransferFormat::Sql && ! $transfer->dry_run && $done > 0) {
            $this->audit->record('data.import', $transfer->user, $transfer->target, [
                'connection' => $transfer->connection_id,
                'format' => $transfer->format->value,
                'statements' => $done,
                'failed' => true,
                'transfer' => $transfer->id,
            ], connection: $transfer->databaseConnection, error: $exception);
        }
    }

    private function discardExport(Transfer $transfer): void
    {
        if ($transfer->kind === TransferKind::Export) {
            $transfer->deleteFiles();
            $transfer->forceFill(['file_path' => null, 'file_size' => null]);
        }
    }

    /**
     * Fehler für die Oberfläche — ohne SQL mit eingesetzten Werten (T-11).
     *
     * @return TransferMessage
     */
    private function describe(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof TransferFailed => $exception->toMessage(),
            $exception instanceof PasswordRequired => ['text' => __('Diese Verbindung speichert ihr Passwort nicht, und im Hintergrund gibt es keines. Starten Sie den Auftrag erneut, nachdem Sie das Passwort eingegeben haben.', locale: 'de')],
            $exception instanceof ConnectionNotAllowed => ['text' => __('Die Verbindung liess sich nicht öffnen.', locale: 'de'), 'detail' => $exception->problem->kind->value],
            $exception instanceof QueryException => ['text' => __('Die Datenbank hat den Auftrag abgebrochen.', locale: 'de'), 'detail' => $this->serverMessage($exception->getPrevious() ?? $exception)],
            $exception instanceof PDOException => ['text' => __('Die Datenbank hat den Auftrag abgebrochen.', locale: 'de'), 'detail' => $this->serverMessage($exception)],
            default => ['text' => __('Der Auftrag ist an einem unerwarteten Fehler gescheitert.', locale: 'de')],
        };
    }

    private function serverMessage(Throwable $exception): string
    {
        return mb_strimwidth($exception->getMessage(), 0, 500, '…');
    }

    private function isExpected(Throwable $exception): bool
    {
        return $exception instanceof TransferFailed
            || $exception instanceof PasswordRequired
            || $exception instanceof ConnectionNotAllowed
            || $exception instanceof QueryException
            || $exception instanceof PDOException;
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, '.', '’');
    }
}
