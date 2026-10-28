<?php

namespace App\Enums;

/**
 * Verschlüsselung der Verbindung (V-01). MySQL und MariaDB kennen über PDO nur «aus» oder «erforderlich»;
 * PostgreSQL zusätzlich «wenn möglich». Zertifikate prüfen folgt mit einer eigenen Anforderung: phpdbadmin verbindet
 * zur einmal geprüften Adresse (V-05), nicht zum Namen.
 */
enum SslMode: string
{
    case Disable = 'disable';
    case Prefer = 'prefer';
    case Require = 'require';

    public function label(): string
    {
        return match ($this) {
            self::Disable => 'Aus',
            self::Prefer => 'Wenn möglich',
            self::Require => 'Erforderlich',
        };
    }

    /**
     * @return list<self>
     */
    public static function for(DatabaseDriver $driver): array
    {
        return match ($driver) {
            DatabaseDriver::Postgres => [self::Disable, self::Prefer, self::Require],
            DatabaseDriver::MariaDb, DatabaseDriver::MySql => [self::Disable, self::Require],
            DatabaseDriver::Sqlite => [],
        };
    }
}
