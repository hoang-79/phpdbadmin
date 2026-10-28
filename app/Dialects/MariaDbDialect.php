<?php

namespace App\Dialects;

use App\Enums\DatabaseDriver;

/**
 * MariaDB 10.6 und neuer: dieselben Fehlercodes wie MySQL; eigene Zeitlimits und Rückgaben (Scheibe 4).
 */
class MariaDbDialect extends MySqlDialect
{
    public function driver(): DatabaseDriver
    {
        return DatabaseDriver::MariaDb;
    }
}
