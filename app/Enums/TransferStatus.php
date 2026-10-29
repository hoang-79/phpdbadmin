<?php

namespace App\Enums;

/**
 * Stand eines Auftrags (B-07). «Entwurf» ist ein hochgeladener Import vor dem ersten Lauf (Zuordnung, Probelauf);
 * «abgebrochen» setzt die Person, der laufende Auftrag hält beim nächsten Paket an.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('vorbereitet'),
            self::Queued => __('wartet'),
            self::Running => __('läuft'),
            self::Done => __('fertig'),
            self::Failed => __('fehlgeschlagen'),
            self::Cancelled => __('abgebrochen'),
        };
    }

    /** Wartet oder läuft — die Oberfläche fragt dann den Stand ab. */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
