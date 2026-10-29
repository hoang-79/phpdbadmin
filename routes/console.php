<?php

use App\Services\Setup\SystemCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schedule;

// Aufbewahrung (Datenmodell § 1.8, § 4): täglich alles löschen, was seine Frist überschritten hat — das Protokoll
// nach phpdbadmin.audit.retention_days, abgelaufene Exporte und Importe samt Dateien (24 Stunden) und alles Weitere,
// was Prunable ist. Braucht den Cron-Eintrag für `schedule:run`.
Schedule::command('model:prune')->daily()->onOneServer()->withoutOverlapping();

// Lebenszeichen des Schedulers für die Systemprüfung (phpdbadmin:check): Läuft der Cron-Eintrag?
Schedule::call(fn () => Cache::put(SystemCheck::SCHEDULE_HEARTBEAT, Date::now()->getTimestamp(), 3 * 86400))
    ->hourly()
    ->name('phpdbadmin:schedule-heartbeat');
