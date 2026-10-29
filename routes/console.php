<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aufbewahrung (Datenmodell § 1.8, § 4): täglich alles löschen, was seine Frist überschritten hat — das Protokoll
// nach phpdbadmin.audit.retention_days, abgelaufene Exporte und Importe samt Dateien (24 Stunden) und alles Weitere,
// was Prunable ist. Braucht den Cron-Eintrag für `schedule:run`.
Schedule::command('model:prune')->daily()->onOneServer()->withoutOverlapping();
