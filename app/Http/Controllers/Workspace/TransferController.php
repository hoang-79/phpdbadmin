<?php

namespace App\Http\Controllers\Workspace;

use App\Connections\TargetConnections;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Http\Requests\Transfers\AnalyzeRequest;
use App\Http\Requests\Transfers\ExportRequest;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Audit\AuditLog;
use App\Services\Catalog\Catalog;
use App\Services\Transfer\Exporter;
use App\Services\Transfer\Statistics;
use App\Services\Transfer\TransferRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seite «Export und Import» je Verbindung (Wireframe 11): Export starten (E-01), Aufträge mit Stand und Download
 * (B-07), Statistik auf Knopfdruck (E-06). `?schema=…&table=…` belegt den Export vor — so verlinkt der Knopf
 * «Exportieren» im Objektkopf hierher.
 */
class TransferController extends TransferWorkspaceController
{
    public function __construct(
        Catalog $catalog,
        TransferRunner $runner,
        private readonly TargetConnections $targets,
        private readonly Exporter $exporter,
        private readonly Statistics $statistics,
        private readonly AuditLog $audit,
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
            $groups = $schema === null ? [] : $this->catalog->groups($connection, $schema, $database);
            $table = $request->query('table');
            $known = [];

            foreach ($groups as $group) {
                if (in_array($group['key'], ['tables', 'views'], true)) {
                    $known = [...$known, ...array_column($group['items'], 'name')];
                }
            }

            $user = $this->user($request);

            return $this->render($request, $connection, 'workspace/transfers/Index', $schemas, $schema, $groups, $database, [
                'selected' => is_string($table) && in_array($table, $known, true) ? $table : null,
                'preset' => ['table' => is_string($table) && in_array($table, $known, true) ? $table : null],
                'transfers' => $this->recent($user, $connection),
                'mode' => $this->mode($connection),
                'canWrite' => $this->canWrite($user, $connection),
                'today' => now()->format('Y-m-d'),
            ]);
        });
    }

    /** Stand der Aufträge für die Abfrage alle paar Sekunden — nur App-Datenbank, keine Zielverbindung. */
    public function status(Request $request, DatabaseConnection $connection): JsonResponse
    {
        return response()->json(['transfers' => $this->recent($this->user($request), $connection)]);
    }

    public function export(ExportRequest $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->guarded($request, $connection, function () use ($request, $connection): RedirectResponse {
            $database = $this->database($request, $connection);
            $schema = (string) $request->string('schema');

            if (! in_array($schema, $this->catalog->schemas($connection, $database), true)) {
                throw ValidationException::withMessages(['schema' => __('Das Schema «:schema» gibt es nicht.', ['schema' => $schema])]);
            }

            $format = TransferFormat::from((string) $request->string('format'));
            $scope = (string) $request->string('scope');
            $table = $scope === 'table' ? (string) $request->string('table') : null;
            $target = $this->targets->open($connection, $database);
            $dialect = $connection->driver->dialect();
            $objects = $this->exporter->objects($target, $dialect, $schema, $table);

            if ($objects === []) {
                throw ValidationException::withMessages(['table' => __('Die Tabelle «:table» gibt es nicht.', ['table' => (string) $table])]);
            }

            $transfer = new Transfer([
                'kind' => TransferKind::Export,
                'format' => $format,
                'status' => TransferStatus::Queued,
                'target' => $table === null ? $schema : $schema.'.'.$table,
                'options' => [
                    'scope' => $scope,
                    'schema' => $schema,
                    'table' => $table,
                    'database' => $database,
                    'delimiter' => $request->delimiter(),
                    'bom' => $request->boolean('bom'),
                    'header' => $request->boolean('header', true),
                    'binary' => $request->input('binary', 'base64'),
                    'content' => $request->input('content', 'both'),
                    'drop' => $request->boolean('drop'),
                ],
                'file_name' => $this->fileName($table ?? $schema, $format),
                'expires_at' => now()->addHours((int) config('phpdbadmin.transfers.retention_hours', 24)),
            ]);

            if (! $this->runner->runsInBackground($connection)) {
                $rows = $this->exporter->estimateRows($target, $dialect, $schema, $this->exporter->withRows($transfer, $objects));
                $this->rejectOverLimit($this->runner->limitProblem($connection, rows: $rows), 'scope');
            }

            $transfer->user()->associate($this->user($request));
            $transfer->databaseConnection()->associate($connection);
            $transfer->save();
            $this->runner->start($transfer);

            Inertia::flash('toast', [
                'type' => $transfer->status === TransferStatus::Failed ? 'error' : 'success',
                'message' => $this->startedMessage($transfer, __('Der Export läuft im Hintergrund.'), __('Export fertig — bereit zum Herunterladen.')),
            ]);

            return to_route('workspace.transfers.index', array_filter([
                'connection' => $connection,
                'schema' => $schema,
                'table' => $table,
                'db' => $database,
            ], fn (mixed $value): bool => $value !== null));
        });
    }

    public function cancel(Request $request, DatabaseConnection $connection, string $transfer): RedirectResponse
    {
        $own = $this->ownTransfer($request, $connection, $transfer);

        $cancelled = Transfer::query()
            ->whereKey($own->id)
            ->whereIn('status', [TransferStatus::Queued->value, TransferStatus::Running->value])
            ->update(['status' => TransferStatus::Cancelled->value, 'finished_at' => now(), 'updated_at' => now()]);

        if ($cancelled === 1) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Auftrag abgebrochen.')]);
        }

        return back();
    }

    public function download(Request $request, DatabaseConnection $connection, string $transfer): StreamedResponse
    {
        $own = $this->ownTransfer($request, $connection, $transfer);
        abort_unless($own->isDownloadable(), 404);

        return Storage::disk(Transfer::DISK)->download((string) $own->file_path, (string) $own->file_name, [
            'Content-Type' => $own->format->mimeType(),
        ]);
    }

    /** Statistik auf Knopfdruck (E-06) — ein Eintrag im Protokoll. */
    public function analyze(AnalyzeRequest $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        $this->authorizeWrite($connection);

        return $this->guarded($request, $connection, function () use ($request, $connection): RedirectResponse {
            $database = $this->database($request, $connection);
            $schema = (string) $request->string('schema');
            $table = (string) $request->string('table');

            $isTable = in_array($schema, $this->catalog->schemas($connection, $database), true)
                && array_any(
                    $connection->driver->dialect()->tables($this->targets->open($connection, $database), $schema),
                    fn (array $object): bool => $object['name'] === $table && $object['type'] === 'table',
                );

            if (! $isTable) {
                throw ValidationException::withMessages(['table' => __('Die Tabelle «:table» gibt es nicht.', ['table' => $table])]);
            }

            $this->statistics->refresh($connection, $schema, $table, $database);
            $this->audit->record('data.analyze', $this->user($request), $schema.'.'.$table, ['connection' => $connection->id]);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Statistik von «:table» aktualisiert.', ['table' => $table])]);

            return back();
        });
    }

    /** «orders-2026-10-29.csv» — nur Zeichen, die jedes Dateisystem kennt. */
    private function fileName(string $name, TransferFormat $format): string
    {
        $safe = trim((string) preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name), '._');

        return ($safe !== '' ? $safe : 'export').'-'.now()->format('Y-m-d').'.'.$format->extension();
    }
}
