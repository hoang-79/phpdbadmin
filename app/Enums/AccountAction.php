<?php

namespace App\Enums;

/**
 * Was mit einem Datenbank-Konto geschieht (W-04) — je Art ein eigener Eintrag im Protokoll.
 */
enum AccountAction: string
{
    case Create = 'create';
    case Drop = 'drop';
    case Grant = 'grant';
    case Revoke = 'revoke';

    public function auditAction(): string
    {
        return match ($this) {
            self::Create => 'server.user.create',
            self::Drop => 'server.user.drop',
            self::Grant => 'server.grant',
            self::Revoke => 'server.revoke',
        };
    }

    public function changesPrivileges(): bool
    {
        return $this === self::Grant || $this === self::Revoke;
    }
}
