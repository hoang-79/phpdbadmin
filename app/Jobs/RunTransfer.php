<?php

namespace App\Jobs;

use App\Connections\TargetConnections;
use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Services\Transfer\TransferRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Ein Auftrag im Hintergrund (B-07). Trägt nur die Kennung — Zugangsdaten liest der Worker aus der App-Datenbank,
 * ein Passwort «nur für diese Sitzung» kommt nie in die Warteschlange. Ein Versuch; was fachlich scheitert, hält
 * TransferRunner selbst am Auftrag fest. Die Laufzeit liegt unter `retry_after` der Warteschlange (config/queue.php).
 */
abstract class RunTransfer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $transferId) {}

    public function handle(TransferRunner $runner, TargetConnections $targets): void
    {
        $transfer = Transfer::query()->find($this->transferId);

        if ($transfer === null) {
            return;
        }

        try {
            $runner->execute($transfer);
        } finally {
            // Der Worker lebt weiter: offene Zielverbindungen nach jedem Auftrag schliessen.
            $targets->releaseAll();
        }
    }

    /** Abgebrochen von aussen (Zeitlimit, Worker beendet): Auftrag als fehlgeschlagen markieren. */
    public function failed(?Throwable $exception): void
    {
        Transfer::query()
            ->whereKey($this->transferId)
            ->whereIn('status', [TransferStatus::Queued->value, TransferStatus::Running->value])
            ->update([
                'status' => TransferStatus::Failed->value,
                'error' => json_encode(['text' => __('Der Auftrag wurde abgebrochen (Zeitlimit oder Worker beendet).', locale: 'de')]),
                'finished_at' => now(),
            ]);
    }
}
