<?php

namespace App\Dialects\Objects;

use App\Enums\DatabaseDriver;
use App\Enums\ObjectKind;

/**
 * Objekte in MariaDB (ab 10.6): wie MySQL, aber `CREATE OR REPLACE` für Sichten, Routinen, Trigger und Ereignisse —
 * Ändern ersetzt an Ort und Stelle, ohne Lücke zwischen Löschen und Anlegen.
 */
class MariaDbObjects extends MySqlObjects
{
    public function replacesInPlace(ObjectKind $kind): bool
    {
        return true;
    }

    protected function driver(): DatabaseDriver
    {
        return DatabaseDriver::MariaDb;
    }
}
