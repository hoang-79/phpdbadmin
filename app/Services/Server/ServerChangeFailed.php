<?php

namespace App\Services\Server;

use RuntimeException;

/**
 * Eine ändernde Aktion ist am Server gescheitert. Die Meldung ist maskiert (kein Passwort); $executed sagt, wie viele
 * Anweisungen davor schon gelaufen sind — sie bleiben bestehen.
 */
final class ServerChangeFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $executed, public readonly ServerPlan $plan)
    {
        parent::__construct($message);
    }
}
