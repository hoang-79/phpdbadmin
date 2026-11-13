<?php

namespace App\Services\Update;

use RuntimeException;

/**
 * Die Aktualisierung geht nicht weiter — mit einer Meldung in der Sprache der Anfrage, die sagt, was los ist und was
 * weiterhin läuft. `retry`: der Schritt darf gleich noch einmal laufen (der neue Code ist noch nicht geladen).
 */
final class UpdateFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retry = false)
    {
        parent::__construct($message);
    }
}
