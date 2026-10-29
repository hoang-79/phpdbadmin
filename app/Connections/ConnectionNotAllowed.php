<?php

namespace App\Connections;

use RuntimeException;

/**
 * Host oder Datei liegt ausserhalb dessen, was diese Installation erlaubt (V-05) — oder lässt sich nicht finden.
 * Die Meldung der Ausnahme ist für Protokolle und sprachneutral (Art und Detail); für Menschen gibt es `problem`.
 * Unterart: TunnelFailed — der SSH-Tunnel kam nicht zustande (V-08); überall, wo diese Ausnahme zur Fehlerkarte
 * wird, gilt das auch für den Tunnel.
 */
class ConnectionNotAllowed extends RuntimeException
{
    final public function __construct(public readonly ConnectionProblem $problem)
    {
        parent::__construct($problem->kind->value.($problem->detail !== null ? ' ('.$problem->detail.')' : ''));
    }
}
