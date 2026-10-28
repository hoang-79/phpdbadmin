<?php

namespace App\Dialects;

use App\Enums\PrivilegeLevel;

/**
 * Worauf ein Recht gilt (W-04): Server, Datenbank, Schema (PostgreSQL) oder Tabelle. Die Namen sind geprüft — sie
 * stehen im Katalog der Verbindung; gequotet wird erst im Dialekt.
 */
final readonly class PrivilegeTarget
{
    public function __construct(
        public PrivilegeLevel $level,
        public ?string $database = null,
        public ?string $schema = null,
        public ?string $table = null,
    ) {}

    /** «Server», «shop», «public.orders» — für Protokoll und Meldungen. */
    public function label(): string
    {
        return match ($this->level) {
            PrivilegeLevel::Server => '*',
            PrivilegeLevel::Database => (string) $this->database,
            PrivilegeLevel::Schema => (string) $this->schema,
            PrivilegeLevel::Table => ($this->schema ?? $this->database).'.'.$this->table,
        };
    }
}
