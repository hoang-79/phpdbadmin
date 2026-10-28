<?php

namespace App\Connections;

use App\Models\DatabaseConnection;
use RuntimeException;

/**
 * Eine ändernde Aktion auf einer schreibgeschützten Verbindung (V-04) — die Oberfläche bietet sie gar nicht an, die
 * Anfrage wird vorher mit 403 abgewiesen; dies ist die letzte Sperre.
 */
final class ReadOnlyConnection extends RuntimeException
{
    public function __construct(public readonly DatabaseConnection $connection)
    {
        parent::__construct('Die Verbindung ist schreibgeschützt.');
    }
}
