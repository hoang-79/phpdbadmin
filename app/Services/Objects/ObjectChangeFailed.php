<?php

namespace App\Services\Objects;

use RuntimeException;

/**
 * Der Server hat eine Änderung an einem Objekt abgelehnt. $restored sagt, ob phpdbadmin die bisherige Definition
 * wiederhergestellt hat (MySQL/MariaDB nach DROP); in einer Transaktion ist ohnehin nichts geschehen.
 */
final class ObjectChangeFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ObjectPlan $plan, public readonly ?bool $restored = null)
    {
        parent::__construct($message);
    }
}
