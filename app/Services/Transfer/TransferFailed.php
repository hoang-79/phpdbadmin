<?php

namespace App\Services\Transfer;

use App\Models\Transfer;
use RuntimeException;

/**
 * Ein Auftrag scheitert mit einer Erklärung für Menschen (deutscher Schlüssel, Platzhalter, Meldung des Servers als
 * Detail). Was so scheitert, ist erwartet und landet nicht im Fehlerprotokoll.
 *
 * @phpstan-import-type TransferMessage from Transfer
 */
final class TransferFailed extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(public readonly string $text, public readonly array $replace = [], public readonly ?string $detail = null)
    {
        parent::__construct($text);
    }

    /**
     * @return TransferMessage
     */
    public function toMessage(): array
    {
        return ['text' => $this->text, 'replace' => $this->replace, 'detail' => $this->detail];
    }
}
