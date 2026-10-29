<?php

namespace App\Dialects\Objects;

use App\Enums\DatabaseDriver;

/**
 * Der Objekt-Dialekt eines Treibers (S-06, S-07).
 */
final class ObjectDialects
{
    public static function for(DatabaseDriver $driver): ObjectDialect
    {
        return match ($driver) {
            DatabaseDriver::MySql => new MySqlObjects,
            DatabaseDriver::MariaDb => new MariaDbObjects,
            DatabaseDriver::Postgres => new PostgresObjects,
            DatabaseDriver::Sqlite => new SqliteObjects,
        };
    }
}
