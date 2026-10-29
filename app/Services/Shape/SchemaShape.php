<?php

namespace App\Services\Shape;

use App\Enums\DatabaseDriver;

/**
 * Die Tabellen eines Schemas (bei MySQL und MariaDB einer Datenbank), gelesen für den Vergleich (S-08).
 */
final readonly class SchemaShape
{
    /**
     * @param  array<string, TableShape>  $tables  nach Name
     */
    public function __construct(
        public DatabaseDriver $driver,
        public string $schema,
        public array $tables,
    ) {}

    public function table(string $name): ?TableShape
    {
        return $this->tables[$name] ?? array_find($this->tables, fn (TableShape $table): bool => strcasecmp($table->name, $name) === 0);
    }
}
