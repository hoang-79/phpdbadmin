<?php

namespace App\Dialects;

/**
 * Ein Datenbank-Konto (W-04): MySQL und MariaDB mit Host ('app'@'%'), PostgreSQL nur mit Namen (Rolle).
 */
final readonly class AccountName
{
    public function __construct(public string $name, public ?string $host = null) {}

    /** «app@%» bzw. «app» — für Protokoll und Meldungen. */
    public function label(): string
    {
        return $this->host === null ? $this->name : $this->name.'@'.$this->host;
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->name === $this->name && $other->host === $this->host;
    }
}
