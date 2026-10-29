<?php

namespace App\Enums;

/**
 * Art eines Auftrags (E-01, E-02, E-03): Export und Sicherung schreiben eine Datei zum Herunterladen, Import und
 * Wiederherstellung lesen eine hochgeladene.
 */
enum TransferKind: string
{
    case Export = 'export';
    case Import = 'import';
    case Backup = 'backup';
    case Restore = 'restore';

    public function label(): string
    {
        return match ($this) {
            self::Export => __('Export'),
            self::Import => __('Import'),
            self::Backup => __('Sicherung'),
            self::Restore => __('Wiederherstellung'),
        };
    }

    /** Entsteht eine Datei zum Herunterladen? */
    public function producesFile(): bool
    {
        return $this === self::Export || $this === self::Backup;
    }
}
