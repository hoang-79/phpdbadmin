<?php

namespace App\Support;

use App\Services\Audit\Masking;

/**
 * Werte aus SQL entfernen, bevor es ins Protokoll geht (B-04). Dünne Hülle um Masking::sql() aus TB 07 — AuditLog::record()
 * maskiert seit TB 07 selbst; diese Klasse bleibt für Stellen, die maskiertes SQL ausserhalb des Protokolls zeigen.
 */
final class SqlMask
{
    public static function mask(string $sql): string
    {
        return Masking::sql($sql);
    }
}
