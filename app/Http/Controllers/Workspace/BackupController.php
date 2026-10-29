<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\TargetConnections;
use App\Enums\ConnectionEnvironment;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Http\Requests\Transfers\BackupRequest;
use App\Http\Requests\Transfers\RestoreRequest;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Backup\BackupRunner;
use App\Services\Catalog\Catalog;
use App\Services\Transfer\TransferRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Seite «Sicherung und Wiederherstellung» je Verbindung (E-03): welche Werkzeuge da sind, Sicherung als Auftrag mit
 * Download bis zur Frist, Wiederherstellen aus einer hochgeladenen Datei — nur mit Recht «schreiben», nie über eine
 * schreibgeschützte Verbindung, mit ausdrücklicher Bestätigung und in Produktion mit dem Namen der Verbindung.
 */
class BackupController extends TransferWorkspaceController
{
    public function __construct(
        Catalog $catalog,
        TransferRunner $runner,
        private readonly TargetConnections $targets,
        private readonly BackupRunner $backups,
    ) {
        parent::__construct($catalog, $runner);
    }

    public function index(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection): Response {
            $database = $this->database($request, $connection);
            $schemas = $this->catalog->schemas($connection, $database);
            $requested = $request->query('schema');
            $schema = is_string($requested) && in_array($requested, $schemas, true) ? $requested : $this->catalog->defaultSchema($connection, $database);
            $user = $this->user($request);
            $databases = $this->databases($connection, $database);

            return $this->render($request, $connection, 'workspace/transfers/Backup', $schemas, $schema, $schema === null ? [] : $this->catalog->groups($connection, $schema, $database), $database, [
                'status' => $this->backups->status($connection),
                'databases' => $databases,
                'defaultDatabase' => match ($connection->driver) {
                    DatabaseDriver::MySql, DatabaseDriver::MariaDb => $schema,
                    DatabaseDriver::Postgres => $database ?? $connection->database ?? ($databases[0] ?? null),
                    DatabaseDriver::Sqlite => $databases[0] ?? null,
                },
                'transfers' => array_values(array_filter(
                    $this->recent($user, $connection),
                    fn (array $transfer): bool => in_array($transfer['kind'], [TransferKind::Backup->value, TransferKind::Restore->value], true),
                )),
                'mode' => $this->mode($connection),
                'canWrite' => $this->canWrite($user, $connection),
                'requiresConfirmation' => $connection->environment === ConnectionEnvironment::Production,
                'maxUploadMegabytes' => intdiv((int) config('phpdbadmin.transfers.max_upload_kb', 512 * 1024), 1024),
            ]);
        });
    }

    public function store(BackupRequest $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection): RedirectResponse {
            $database = $this->chosenDatabase($connection, $this->database($request, $connection), (string) $request->string('database'));
            $driver = $connection->driver;
            $format = match ($driver) {
                DatabaseDriver::Sqlite => TransferFormat::SqliteFile,
                DatabaseDriver::Postgres => $request->input('pg_format', 'custom') === 'plain' ? TransferFormat::Sql : TransferFormat::PgDump,
                default => TransferFormat::Sql,
            };

            if (! $this->backups->status($connection)['canBackup']) {
                throw ValidationException::withMessages(['database' => __('Auf dem phpdbadmin-Server fehlt das Werkzeug für die Sicherung — siehe «Werkzeuge».')]);
            }

            if (! $this->runner->runsInBackground($connection)) {
                $this->rejectLargeDatabase($connection, $database);
            }

            $transfer = new Transfer([
                'kind' => TransferKind::Backup,
                'format' => $format,
                'status' => TransferStatus::Queued,
                'target' => $database,
                'options' => [
                    // PostgreSQL öffnet die gewählte Datenbank; MySQL/MariaDB nennen sie dem Werkzeug.
                    'database' => $driver === DatabaseDriver::Sqlite ? null : $database,
                    'open_database' => $driver === DatabaseDriver::Postgres ? $database : null,
                    'objects' => $request->boolean('objects', true),
                ],
                'file_name' => $this->fileName($driver === DatabaseDriver::Sqlite ? pathinfo($database, PATHINFO_FILENAME) : $database, $format, $this->user($request)->timezone),
                'expires_at' => now()->addHours((int) config('phpdbadmin.transfers.retention_hours', 24)),
            ]);
            $transfer->user()->associate($this->user($request));
            $transfer->databaseConnection()->associate($connection);
            $transfer->save();
            $this->runner->start($transfer);

            Inertia::flash('toast', [
                'type' => $transfer->status === TransferStatus::Failed ? 'error' : 'success',
                'message' => $this->startedMessage($transfer, __('Die Sicherung läuft im Hintergrund.'), __('Sicherung fertig — bereit zum Herunterladen.')),
            ]);

            return back();
        });
    }

    public function restore(RestoreRequest $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        $this->authorizeWrite($connection);

        return $this->guarded($request, $connection, function () use ($request, $connection): RedirectResponse {
            $database = $this->chosenDatabase($connection, $this->database($request, $connection), (string) $request->string('database'));

            if ($connection->environment === ConnectionEnvironment::Production && $request->input('confirmation') !== $connection->name) {
                throw ValidationException::withMessages(['confirmation' => __('Tippen Sie zur Bestätigung den Namen der Verbindung «:name» ein.', ['name' => $connection->name])]);
            }

            if (! $this->backups->status($connection)['canRestore']) {
                throw ValidationException::withMessages(['file' => __('Auf dem phpdbadmin-Server fehlt das Werkzeug für die Wiederherstellung — siehe «Werkzeuge».')]);
            }

            /** @var UploadedFile $file */
            $file = $request->file('file');
            $format = BackupRunner::detectFormat((string) $file->getRealPath());
            $fits = match ($connection->driver) {
                DatabaseDriver::Sqlite => $format === TransferFormat::SqliteFile,
                DatabaseDriver::Postgres => $format === TransferFormat::PgDump || $format === TransferFormat::Sql,
                default => $format === TransferFormat::Sql,
            };

            if ($format === null || ! $fits) {
                throw ValidationException::withMessages(['file' => $this->wrongFileText($connection->driver)]);
            }

            $this->rejectOverLimit($this->runner->limitProblem($connection, bytes: (int) $file->getSize()), 'file');

            $transfer = new Transfer([
                'kind' => TransferKind::Restore,
                'format' => $format,
                'status' => TransferStatus::Queued,
                'target' => $database,
                'options' => [
                    'database' => $connection->driver === DatabaseDriver::Sqlite ? null : $database,
                    // Ohne geschützten Client spielt der SQL-Ausführer die Datei in dieser Datenbank ein (E-02).
                    'schema' => $connection->driver === DatabaseDriver::Postgres ? 'public' : $database,
                    'open_database' => $connection->driver === DatabaseDriver::Sqlite ? null : $database,
                ],
                'file_name' => mb_strimwidth($file->getClientOriginalName(), 0, 200),
                'expires_at' => now()->addHours((int) config('phpdbadmin.transfers.retention_hours', 24)),
            ]);
            $transfer->setAttribute('id', $transfer->newUniqueId());
            $transfer->user()->associate($this->user($request));
            $transfer->databaseConnection()->associate($connection);

            $path = $file->storeAs($transfer->directory(), 'sicherung.'.$format->extension(), Transfer::DISK);
            abort_if($path === false, 500);
            $transfer->forceFill(['file_path' => $path, 'file_size' => $file->getSize()])->save();
            $this->runner->start($transfer);

            Inertia::flash('toast', [
                'type' => $transfer->status === TransferStatus::Failed ? 'error' : 'success',
                'message' => $this->startedMessage($transfer, __('Die Wiederherstellung läuft im Hintergrund.'), __('Wiederherstellung fertig.')),
            ]);

            return back();
        });
    }

    /**
     * Datenbanken, die sich sichern bzw. als Ziel wählen lassen: MySQL/MariaDB die Datenbanken (Schemas) des
     * Katalogs, PostgreSQL die Datenbanken des Servers bzw. die eingetragene, SQLite die Datei.
     *
     * @return list<string>
     */
    private function databases(DatabaseConnection $connection, ?string $database): array
    {
        return match ($connection->driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => $this->catalog->schemas($connection, $database),
            DatabaseDriver::Postgres => $this->catalog->databases($connection) ?? [(string) ($connection->database ?? Catalog::POSTGRES_MAINTENANCE_DATABASE)],
            DatabaseDriver::Sqlite => [basename((string) $connection->sqlite_path)],
        };
    }

    /**
     * @throws ValidationException
     */
    private function chosenDatabase(DatabaseConnection $connection, ?string $database, string $chosen): string
    {
        if (! in_array($chosen, $this->databases($connection, $database), true)) {
            throw ValidationException::withMessages(['database' => __('Die Datenbank «:database» gibt es nicht.', ['database' => $chosen])]);
        }

        return $chosen;
    }

    /**
     * Ohne Worker läuft die Sicherung in der Anfrage — dann nur kleine Datenbanken (Grenze wie beim Import).
     *
     * @throws ValidationException
     */
    private function rejectLargeDatabase(DatabaseConnection $connection, string $database): void
    {
        $limit = (int) config('phpdbadmin.transfers.sync_max_bytes', 20 * 1024 * 1024);
        $size = $this->size($connection, $database);

        if ($size === null || $size <= $limit) {
            return;
        }

        $replace = ['amount' => number_format($size / 1048576, 1, '.', '’'), 'limit' => number_format($limit / 1048576, 1, '.', '’')];

        throw ValidationException::withMessages(['database' => $this->runner->passwordOnlyInSession($connection)
            ? __('Diese Verbindung speichert ihr Passwort nicht, deshalb läuft die Sicherung hier in der Anfrage — und die schafft höchstens :limit MB. Die Datenbank hat etwa :amount MB. Speichern Sie das Passwort in der Verbindung, dann läuft die Sicherung im Hintergrund.', $replace)
            : __('Ohne Hintergrund-Worker sichert phpdbadmin höchstens :limit MB in einer Anfrage; die Datenbank hat etwa :amount MB. Richten Sie einen Worker ein (php artisan queue:work).', $replace),
        ]);
    }

    /**
     * Grösse der Datenbank laut Server (Daten und Indizes) bzw. der Datei — null, wenn unbekannt.
     */
    private function size(DatabaseConnection $connection, string $database): ?int
    {
        try {
            return match ($connection->driver) {
                DatabaseDriver::Sqlite => (int) filesize($this->targets->credentials($connection)['address']),
                DatabaseDriver::Postgres => (int) $this->targets->open($connection, $database)->selectOne('select pg_database_size(current_database()) as size')?->size,
                default => (int) $this->targets->open($connection)->selectOne('select coalesce(sum(data_length + index_length), 0) as size from information_schema.tables where table_schema = ?', [$database])?->size,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /** «shop-2026-10-29-0315.sql» in der Zeitzone des Kontos — nur Zeichen, die jedes Dateisystem kennt. */
    private function fileName(string $name, TransferFormat $format, string $timezone): string
    {
        $safe = trim((string) preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name), '._');

        return ($safe !== '' ? $safe : 'sicherung').'-'.now($timezone)->format('Y-m-d-Hi').'.'.$format->extension();
    }

    private function wrongFileText(DatabaseDriver $driver): string
    {
        return match ($driver) {
            DatabaseDriver::Sqlite => __('Das ist keine SQLite-Datei. Wiederherstellen lässt sich eine Sicherung, die phpdbadmin oder sqlite3 erstellt hat.'),
            DatabaseDriver::Postgres => __('Das ist keine Sicherung von pg_dump (Custom- oder Textformat). Gepackte Dateien bitte vorher entpacken.'),
            default => __('Das ist keine SQL-Datei von mysqldump oder mariadb-dump. Gepackte Dateien bitte vorher entpacken.'),
        };
    }
}
