<?php

namespace App\Services\Sql\Explain;

use RuntimeException;

/**
 * EXPLAIN wurde abgewiesen (Q-06) — bevor etwas lief (falsche Art, gefährlich, ANALYZE für Änderungen) oder weil der
 * Server die Anweisung nicht erklären konnte ($fromServer). Die Meldung ist übersetzt.
 */
final class ExplainRejected extends RuntimeException
{
    public function __construct(string $message, public readonly bool $fromServer = false)
    {
        parent::__construct($message);
    }
}
