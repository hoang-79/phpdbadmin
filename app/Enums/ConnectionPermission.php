<?php

namespace App\Enums;

/**
 * Recht eines Kontos an einer geteilten Verbindung (A-07; Verwaltung kommt mit TB 07).
 */
enum ConnectionPermission: string
{
    case Read = 'read';
    case Write = 'write';
    case Manage = 'manage';
}
