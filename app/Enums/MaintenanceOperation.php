<?php

namespace App\Enums;

/**
 * Wartung (W-05): Statistik aktualisieren, Platz freigeben (VACUUM bzw. OPTIMIZE) und Integrität prüfen. Welche
 * davon ein Treiber kann, sagt der Dialekt (Dialect::maintenanceOperations).
 */
enum MaintenanceOperation: string
{
    case Analyze = 'analyze';
    case Vacuum = 'vacuum';
    case Optimize = 'optimize';
    case Check = 'check';
}
