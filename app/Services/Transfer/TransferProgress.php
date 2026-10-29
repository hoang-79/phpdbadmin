<?php

namespace App\Services\Transfer;

use App\Enums\TransferStatus;
use App\Models\Transfer;

/**
 * Fortschritt eines laufenden Auftrags in `transfers.progress` und `rows` (B-07) — höchstens zweimal je Sekunde
 * geschrieben. Bei jedem Schreiben fragt der Lauf nach, ob die Person abgebrochen hat.
 */
final class TransferProgress
{
    private float $lastWrite = 0.0;

    public function __construct(private readonly Transfer $transfer, private readonly float $interval = 0.5) {}

    /**
     * @param  float|null  $fraction  Anteil 0…1, wo bekannt (Bytes der Datei, Zeilen gegen die Schätzung)
     *
     * @throws TransferCancelled
     */
    public function report(int $rows, ?float $fraction = null): void
    {
        $now = microtime(true);

        if ($now - $this->lastWrite < $this->interval) {
            return;
        }

        $this->lastWrite = $now;
        $this->checkCancelled();

        $this->transfer->forceFill([
            'rows' => $rows,
            'progress' => $fraction === null ? $this->transfer->progress : (int) min(99, max(0, floor($fraction * 100))),
        ])->save();
    }

    /**
     * @throws TransferCancelled
     */
    public function checkCancelled(): void
    {
        // Roh gelesen: über Eloquent käme der Stand als Enum zurück.
        $status = Transfer::query()->whereKey($this->transfer->id)->toBase()->value('status');

        if ($status === TransferStatus::Cancelled->value) {
            throw new TransferCancelled;
        }
    }
}
