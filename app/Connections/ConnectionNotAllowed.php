<?php

namespace App\Connections;

use RuntimeException;

/**
 * Host oder Datei liegt ausserhalb dessen, was diese Installation erlaubt (V-05) — oder lässt sich nicht finden.
 */
final class ConnectionNotAllowed extends RuntimeException
{
    public function __construct(public readonly ConnectionProblem $problem)
    {
        parent::__construct($problem->title());
    }
}
