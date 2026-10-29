<?php

namespace App\Services\Objects;

use RuntimeException;

/**
 * Eine Definition passt nicht zu dem, was die Seite ändern will (andere Art, anderes Schema, mehrere Anweisungen …) —
 * nichts wurde ausgeführt. Die Meldung ist übersetzt und erscheint am Feld.
 */
final class DefinitionRejected extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'definition')
    {
        parent::__construct($message);
    }
}
