<?php

namespace App\Dialects;

use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;
use Throwable;

/**
 * MariaDB 10.6 und neuer: dieselben Fehlercodes wie MySQL, aber ein eigenes Zeitlimit (max_statement_time in
 * Sekunden, gilt für jede Anweisung) mit Fehler 1969 statt stillem Abbruch (F-2).
 */
class MariaDbDialect extends MySqlDialect
{
    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::MariaDb;
    }

    public function setStatementTimeout(Connection $connection, int $milliseconds): void
    {
        $connection->statement('set session max_statement_time = '.number_format(max(0, $milliseconds) / 1000, 3, '.', ''));
    }

    public function isTimeout(Throwable $error): bool
    {
        [$state, $code] = $this->errorCodes($error);

        return $code === 1969 || $state === '70100';
    }
}
