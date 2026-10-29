<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\TargetConnections;
use App\Enums\ConnectionEnvironment;
use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Http\Requests\Transfers\RunImportRequest;
use App\Http\Requests\Transfers\UpdateImportRequest;
use App\Http\Requests\Transfers\UploadImportRequest;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Catalog\Catalog;
use App\Services\Transfer\ColumnInfo;
use App\Services\Transfer\Csv;
use App\Services\Transfer\CsvImporter;
use App\Services\Transfer\TransferRunner;
use App\Support\TransferSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import in Schritten (E-02, Wireframe 11): Datei hochladen → Zuordnung → Probelauf → Importieren. Schreiben nur
 * mit Recht «schreiben» und nie über eine schreibgeschützte Verbindung; auf Produktion zusätzlich mit dem Namen der
 * Verbindung. Jeder echte Import steht einmal im Protokoll (data.import).
 */
class ImportController extends TransferWorkspaceController
{
    public function __construct(
        Catalog $catalog,
        TransferRunner $runner,
        private readonly TargetConnections $targets,
        private readonly CsvImporter $csv,
    ) {
        parent::__construct($catalog, $runner);
    }

    public function store(UploadImportRequest $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        $this->authorizeWrite($connection);

        return $this->guarded($request, $connection, function () use ($request, $connection): RedirectResponse {
            $database = $this->database($request, $connection);
            $schema = (string) $request->string('schema');
            $format = TransferFormat::from((string) $request->string('format'));
            $table = $format === TransferFormat::Csv ? (string) $request->string('table') : null;
            $this->ensureTarget($connection, $database, $schema, $table);

            /** @var UploadedFile $file */
            $file = $request->file('file');
            $transfer = new Transfer([
                'kind' => TransferKind::Import,
                'format' => $format,
                'status' => TransferStatus::Draft,
                'target' => $table === null ? $schema : $schema.'.'.$table,
                'options' => [
                    'schema' => $schema,
                    'table' => $table,
                    'database' => $database,
                    // MySQL/MariaDB mit «alle Datenbanken»: die SQL-Datei läuft in der gewählten Datenbank.
                    'open_database' => $format === TransferFormat::Sql && $connection->database === null && $connection->driver->isServer() && $connection->driver !== DatabaseDriver::Postgres ? $schema : null,
                ],
                'file_name' => mb_strimwidth($file->getClientOriginalName(), 0, 200),
                'expires_at' => now()->addHours((int) config('phpdbadmin.transfers.retention_hours', 24)),
            ]);
            $transfer->setAttribute('id', $transfer->newUniqueId());
            $transfer->user()->associate($this->user($request));
            $transfer->databaseConnection()->associate($connection);

            $path = $file->storeAs($transfer->directory(), 'source.'.$format->extension(), Transfer::DISK);
            abort_if($path === false, 500);
            $transfer->forceFill(['file_path' => $path, 'file_size' => $file->getSize()]);

            if ($format === TransferFormat::Csv) {
                $transfer->options = [...$transfer->options ?? [], ...$this->analysis($connection, $transfer)];
            }

            $transfer->save();

            return to_route('workspace.transfers.edit', $this->routeParameters($transfer));
        });
    }

    public function edit(Request $request, DatabaseConnection $connection, string $transfer): Response|RedirectResponse
    {
        $own = $this->ownTransfer($request, $connection, $transfer);
        abort_unless($own->kind === TransferKind::Import, 404);

        return $this->guarded($request, $connection, function () use ($request, $connection, $own): Response {
            $database = $own->option('database');
            $schema = (string) $own->option('schema');
            $schemas = $this->catalog->schemas($connection, $database);
            $groups = in_array($schema, $schemas, true) ? $this->catalog->groups($connection, $schema, $database) : [];
            $columns = $own->format === TransferFormat::Csv && $own->option('table') !== null
                ? array_map(
                    fn (ColumnInfo $column): array => $column->toArray(),
                    array_values(array_filter(
                        ColumnInfo::of($this->targets->open($connection, $database), $connection->driver, $schema, (string) $own->option('table')),
                        fn (ColumnInfo $column): bool => ! $column->generated,
                    )),
                )
                : [];

            return $this->render($request, $connection, 'workspace/transfers/Import', $schemas, $schema, $groups, $database, [
                'selected' => $own->option('table'),
                'transfer' => [
                    ...TransferSummary::of($own),
                    'schema' => $schema,
                    'table' => $own->option('table'),
                    'options' => [
                        'delimiter' => Csv::DELIMITERS[$own->option('delimiter', ',') ?? ','] ?? 'comma',
                        'encoding' => $own->option('encoding'),
                        'bom' => $own->flag('bom'),
                        'header' => $own->flag('header', true),
                        'columns' => $own->options['columns'] ?? [],
                        'mapping' => $own->options['mapping'] ?? [],
                        'records' => $own->options['records'] ?? null,
                        'onError' => $own->option('on_error', 'skip'),
                        'analyze' => $own->flag('analyze', true),
                    ],
                    'result' => TransferSummary::result($own),
                    'imported' => $own->status === TransferStatus::Done && ! $own->dry_run,
                    'statusUrl' => route('workspace.transfers.status', array_filter(['connection' => $connection->id, 'db' => $database])),
                ],
                'columns' => $columns,
                'mode' => $this->mode($connection),
                'canWrite' => $this->canWrite($this->user($request), $connection),
                'requiresConfirmation' => $connection->environment === ConnectionEnvironment::Production,
            ]);
        });
    }

    /** Trennzeichen, Kodierung, Kopfzeile ändern — die Datei wird neu gelesen, ein früherer Probelauf gilt nicht mehr. */
    public function update(UpdateImportRequest $request, DatabaseConnection $connection, string $transfer): Response|RedirectResponse
    {
        $own = $this->ownTransfer($request, $connection, $transfer);
        $this->authorizeWrite($connection);
        $this->ensureChangeable($own);
        abort_unless($own->format === TransferFormat::Csv, 404);

        return $this->guarded($request, $connection, function () use ($request, $connection, $own): RedirectResponse {
            $own->options = [...$own->options ?? [], ...$this->analysis($connection, $own, $request->delimiter(), (string) $request->string('encoding'), $request->boolean('header'))];
            $own->forceFill(['status' => TransferStatus::Draft, 'dry_run' => false, 'result' => null, 'error' => null, 'progress' => 0, 'rows' => 0])->save();

            return to_route('workspace.transfers.edit', $this->routeParameters($own));
        });
    }

    /** Probelauf oder Import starten. */
    public function run(RunImportRequest $request, DatabaseConnection $connection, string $transfer): Response|RedirectResponse
    {
        $own = $this->ownTransfer($request, $connection, $transfer);
        $this->authorizeWrite($connection);
        $this->ensureChangeable($own);

        return $this->guarded($request, $connection, function () use ($request, $connection, $own): RedirectResponse {
            $dryRun = $request->boolean('dry_run');
            $options = [
                ...$own->options ?? [],
                'on_error' => $request->input('on_error', 'skip'),
                'analyze' => $request->boolean('analyze', true),
            ];

            if ($own->format === TransferFormat::Csv) {
                $options['mapping'] = $this->validatedMapping($request, $connection, $own);
            }

            if (! $dryRun && $connection->environment === ConnectionEnvironment::Production && $request->input('confirmation') !== $connection->name) {
                throw ValidationException::withMessages(['confirmation' => __('Tippen Sie zur Bestätigung den Namen der Verbindung «:name» ein.', ['name' => $connection->name])]);
            }

            $this->rejectOverLimit($this->runner->limitProblem($connection, bytes: $own->file_size), 'run');

            $own->forceFill(['options' => $options, 'dry_run' => $dryRun, 'result' => null])->save();
            $this->runner->start($own);

            Inertia::flash('toast', [
                'type' => $own->status === TransferStatus::Failed ? 'error' : 'success',
                'message' => $this->startedMessage(
                    $own,
                    $dryRun ? __('Der Probelauf läuft im Hintergrund.') : __('Der Import läuft im Hintergrund.'),
                    $dryRun ? __('Probelauf fertig — nichts wurde geschrieben.') : __('Import fertig.'),
                ),
            ]);

            return to_route('workspace.transfers.edit', $this->routeParameters($own));
        });
    }

    /**
     * Schema (und für CSV die Tabelle) muss es geben — sonst ein Fehler am Formular.
     *
     * @throws ValidationException
     */
    private function ensureTarget(DatabaseConnection $connection, ?string $database, string $schema, ?string $table): void
    {
        if (! in_array($schema, $this->catalog->schemas($connection, $database), true)) {
            throw ValidationException::withMessages(['schema' => __('Das Schema «:schema» gibt es nicht.', ['schema' => $schema])]);
        }

        if ($table === null) {
            return;
        }

        $isTable = array_any(
            $connection->driver->dialect()->tables($this->targets->open($connection, $database), $schema),
            fn (array $object): bool => $object['name'] === $table && $object['type'] === 'table',
        );

        if (! $isTable) {
            throw ValidationException::withMessages(['table' => __('Die Tabelle «:table» gibt es nicht.', ['table' => $table])]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function analysis(DatabaseConnection $connection, Transfer $transfer, ?string $delimiter = null, ?string $encoding = null, ?bool $header = null): array
    {
        $database = $transfer->option('database');
        $columns = ColumnInfo::of($this->targets->open($connection, $database), $connection->driver, (string) $transfer->option('schema'), (string) $transfer->option('table'));

        return $this->csv->analyse(
            Storage::disk(Transfer::DISK)->path((string) $transfer->file_path),
            $columns,
            $delimiter,
            $encoding,
            $header,
        );
    }

    /**
     * Je Spalte der Datei eine Spalte der Tabelle oder nichts — jede Spalte der Tabelle höchstens einmal, mindestens
     * eine zugeordnet, keine generierte.
     *
     * @return list<string|null>
     *
     * @throws ValidationException
     */
    private function validatedMapping(RunImportRequest $request, DatabaseConnection $connection, Transfer $transfer): array
    {
        $fileColumns = count((array) ($transfer->options['columns'] ?? []));
        $mapping = array_map(fn (mixed $name): ?string => is_string($name) && $name !== '' ? $name : null, array_values((array) $request->input('mapping', [])));
        $available = [];

        foreach (ColumnInfo::of($this->targets->open($connection, $transfer->openDatabase()), $connection->driver, (string) $transfer->option('schema'), (string) $transfer->option('table')) as $column) {
            if (! $column->generated) {
                $available[] = $column->name;
            }
        }

        $chosen = array_values(array_filter($mapping, fn (?string $name): bool => $name !== null));
        $unknown = array_values(array_diff($chosen, $available));

        $problem = match (true) {
            count($mapping) !== $fileColumns => __('Die Zuordnung passt nicht zur Datei — laden Sie die Seite neu.'),
            $chosen === [] => __('Ordnen Sie mindestens eine Spalte zu.'),
            $unknown !== [] => __('Die Spalte «:column» gibt es in dieser Tabelle nicht.', ['column' => $unknown[0]]),
            count($chosen) !== count(array_unique($chosen)) => __('Jede Spalte der Tabelle lässt sich nur einmal zuordnen.'),
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['mapping' => $problem]);
        }

        return $mapping;
    }

    /** Läuft der Auftrag noch oder ist der echte Import schon gelaufen, lässt er sich nicht mehr ändern. */
    private function ensureChangeable(Transfer $transfer): void
    {
        abort_unless($transfer->kind === TransferKind::Import, 404);
        abort_if($transfer->status->isActive() || ($transfer->status === TransferStatus::Done && ! $transfer->dry_run), 409);
    }

    /**
     * @return array<string, string>
     */
    private function routeParameters(Transfer $transfer): array
    {
        return array_filter([
            'connection' => $transfer->connection_id,
            'transfer' => $transfer->id,
            'db' => $transfer->option('database'),
        ], fn (?string $value): bool => $value !== null);
    }
}
