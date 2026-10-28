<?php

namespace App\Connections;

use App\Models\DatabaseConnection;
use RuntimeException;

/**
 * Die Verbindung speichert kein Passwort («in jeder Sitzung fragen», V-03), und in dieser Sitzung wurde noch keines
 * eingegeben.
 */
final class PasswordRequired extends RuntimeException
{
    public function __construct(public readonly DatabaseConnection $connection)
    {
        parent::__construct('Passwort für diese Sitzung nötig.');
    }
}
