<?php

namespace App\Enums;

/**
 * Art eines Auftrags (E-01, E-02): Export schreibt eine Datei zum Herunterladen, Import liest eine hochgeladene.
 */
enum TransferKind: string
{
    case Export = 'export';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Export => __('Export'),
            self::Import => __('Import'),
        };
    }
}
