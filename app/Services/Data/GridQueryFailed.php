<?php

namespace App\Services\Data;

use RuntimeException;

/**
 * Der gewünschte Ausschnitt liess sich nicht lesen — mit Ursache für die Fehlerkarte (O-09): unbekannte Spalte,
 * ungültiges SQL im freien WHERE oder Zeitlimit. Verbindungsfehler gehören nicht hierher.
 */
final class GridQueryFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?string $serverMessage = null)
    {
        parent::__construct($message);
    }

    /**
     * @return array{reason: string, message: string, serverMessage: string|null}
     */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'message' => $this->getMessage(), 'serverMessage' => $this->serverMessage];
    }
}
