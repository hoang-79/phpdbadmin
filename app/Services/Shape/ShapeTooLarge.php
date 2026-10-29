<?php

namespace App\Services\Shape;

use RuntimeException;

/**
 * Zu viele Tabellen für einen Vergleich in einer Anfrage (je Tabelle vier Abfragen).
 */
final class ShapeTooLarge extends RuntimeException
{
    public function __construct(public readonly int $tables, public readonly int $limit)
    {
        parent::__construct('too many tables: '.$tables.' > '.$limit);
    }
}
