<?php

namespace App\Services\Schema;

use RuntimeException;

/**
 * Eine Strukturänderung ist gescheitert. $applied sagt, wie viele Anweisungen davor schon gelaufen sind und bestehen
 * bleiben — nur bei MySQL und MariaDB mehr als null; PostgreSQL und SQLite haben alles zurückgerollt (N-03).
 */
final class SchemaChangeFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $applied, public readonly SchemaPlan $plan)
    {
        parent::__construct($message);
    }

    /**
     * Für die Oberfläche: was gelaufen ist, was nicht.
     *
     * @return array{message: string, applied: int, failed: int|null, rolledBack: bool, statements: list<string>}
     */
    public function toArray(): array
    {
        $rolledBack = $this->plan->mode->rollsBack();

        return [
            'message' => $this->getMessage(),
            'applied' => $this->applied,
            // Nummer der Anweisung, die scheiterte (ab 1); nach dem Rückrollen unbekannt bzw. die Prüfung danach.
            'failed' => $rolledBack ? null : min($this->applied + 1, count($this->plan->statements)),
            'rolledBack' => $rolledBack,
            'statements' => $this->plan->statements,
        ];
    }
}
