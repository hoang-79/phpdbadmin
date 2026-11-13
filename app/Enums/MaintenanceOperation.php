<?php

namespace App\Enums;

/**
 * Wartung (W-05, W-06): Statistik aktualisieren, Platz freigeben (VACUUM bzw. OPTIMIZE), Integrität prüfen,
 * reparieren und Prüfsumme (MySQL/MariaDB), Indizes neu aufbauen (PostgreSQL, SQLite). Welche davon ein Treiber kann,
 * sagt der Dialekt (Dialect::maintenanceOperations).
 */
enum MaintenanceOperation: string
{
    case Analyze = 'analyze';
    case Vacuum = 'vacuum';
    case Optimize = 'optimize';
    case Check = 'check';
    case Repair = 'repair';
    case Checksum = 'checksum';
    case Reindex = 'reindex';
}
