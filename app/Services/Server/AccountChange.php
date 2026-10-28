<?php

namespace App\Services\Server;

use App\Dialects\AccountName;
use App\Dialects\PrivilegeTarget;
use App\Enums\AccountAction;

/**
 * Eine geprüfte Eingabe für Konten und Rechte (W-04): anlegen, löschen, erteilen, entziehen.
 */
final readonly class AccountChange
{
    /**
     * @param  list<string>  $privileges
     */
    public function __construct(
        public AccountAction $action,
        public AccountName $account,
        public ?string $password = null,
        public ?PrivilegeTarget $target = null,
        public array $privileges = [],
    ) {}
}
