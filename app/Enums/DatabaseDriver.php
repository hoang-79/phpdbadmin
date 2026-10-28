<?php

namespace App\Enums;

use App\Dialects\Dialect;
use App\Dialects\MariaDbDialect;
use App\Dialects\MySqlDialect;
use App\Dialects\PostgresDialect;
use App\Dialects\SqliteDialect;

/**
 * Die vier Treiber (V-01). Der Wert ist der Laravel-Treibername.
 */
enum DatabaseDriver: string
{
    case MariaDb = 'mariadb';
    case MySql = 'mysql';
    case Postgres = 'pgsql';
    case Sqlite = 'sqlite';

    public function label(): string
    {
        return match ($this) {
            self::MariaDb => 'MariaDB',
            self::MySql => 'MySQL',
            self::Postgres => 'PostgreSQL',
            self::Sqlite => 'SQLite',
        };
    }

    /** Älteste unterstützte Version (Wireframe 04). */
    public function minimumVersion(): string
    {
        return match ($this) {
            self::MariaDb => '10.6',
            self::MySql => '8.0',
            self::Postgres => '14',
            self::Sqlite => '3.35',
        };
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::MariaDb, self::MySql => 3306,
            self::Postgres => 5432,
            self::Sqlite => null,
        };
    }

    public function dialect(): Dialect
    {
        return match ($this) {
            self::MariaDb => new MariaDbDialect,
            self::MySql => new MySqlDialect,
            self::Postgres => new PostgresDialect,
            self::Sqlite => new SqliteDialect,
        };
    }

    public function isServer(): bool
    {
        return $this !== self::Sqlite;
    }
}
