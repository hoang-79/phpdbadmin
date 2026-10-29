<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\TransferStatus;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Catalog\Catalog;
use App\Services\Transfer\TransferRunner;
use App\Support\TransferSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gemeinsames der Seiten «Export und Import» (Wireframe 11): eigene Aufträge finden (fremde sind 404 — auch bei
 * derselben Verbindung, T-16), Liste der letzten Aufträge, Betriebsart (Hintergrund oder Anfrage).
 *
 * @phpstan-import-type TransferMessage from Transfer
 */
abstract class TransferWorkspaceController extends WorkspaceController
{
    public function __construct(Catalog $catalog, protected readonly TransferRunner $runner)
    {
        parent::__construct($catalog);
    }

    protected function ownTransfer(Request $request, DatabaseConnection $connection, string $id): Transfer
    {
        return Transfer::query()
            ->whereKey($id)
            ->where('user_id', $this->user($request)->id)
            ->where('connection_id', $connection->id)
            ->firstOrFail();
    }

    /**
     * Die letzten Aufträge dieses Kontos an dieser Verbindung, die noch nicht abgelaufen sind.
     *
     * @return list<array<string, mixed>>
     */
    protected function recent(User $user, DatabaseConnection $connection): array
    {
        return array_values(Transfer::query()
            ->where('user_id', $user->id)
            ->where('connection_id', $connection->id)
            ->where('expires_at', '>', now())
            ->latest()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Transfer $transfer): array => TransferSummary::of($transfer))
            ->all());
    }

    /**
     * Darf dieses Konto über diese Verbindung schreiben (Import, Statistik)? Schreibgeschützte Verbindungen nie.
     */
    protected function canWrite(User $user, DatabaseConnection $connection): bool
    {
        return ! $connection->read_only && Gate::forUser($user)->allows('write', $connection);
    }

    protected function authorizeWrite(DatabaseConnection $connection): void
    {
        Gate::authorize('write', $connection);
        abort_if($connection->read_only, 403, __('Diese Verbindung ist schreibgeschützt.'));
    }

    /**
     * Wie Aufträge an dieser Verbindung laufen — die Oberfläche erklärt es vor dem Start.
     *
     * @return array{background: bool, hasWorker: bool, passwordOnlyInSession: bool, maxRows: int, maxMegabytes: int}
     */
    protected function mode(DatabaseConnection $connection): array
    {
        return [
            'background' => $this->runner->runsInBackground($connection),
            'hasWorker' => $this->runner->hasWorker(),
            'passwordOnlyInSession' => $this->runner->passwordOnlyInSession($connection),
            'maxRows' => (int) config('phpdbadmin.transfers.sync_max_rows', 100000),
            'maxMegabytes' => intdiv((int) config('phpdbadmin.transfers.sync_max_bytes', 20 * 1024 * 1024), 1024 * 1024),
        ];
    }

    /**
     * Grenze ohne Worker überschritten: als Fehler am Formular.
     *
     * @param  TransferMessage|null  $problem
     *
     * @throws ValidationException
     */
    protected function rejectOverLimit(?array $problem, string $field): void
    {
        $view = TransferSummary::message($problem);

        if ($view !== null) {
            throw ValidationException::withMessages([$field => $view['text']]);
        }
    }

    /**
     * Toast nach dem Start: im Hintergrund, fertig oder gescheitert.
     */
    protected function startedMessage(Transfer $transfer, string $background, string $done): string
    {
        return match (true) {
            $transfer->in_background => $background,
            $transfer->status === TransferStatus::Done => $done,
            default => TransferSummary::message($transfer->error)['text'] ?? __('Der Auftrag ist fehlgeschlagen.'),
        };
    }
}
