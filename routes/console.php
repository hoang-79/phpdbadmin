<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fristen der App-Datenbank (Datenmodell § 4): abgelaufene Exporte und Importe samt Dateien (24 Stunden) und alles
// Weitere, was Prunable ist.
Schedule::command('model:prune')->daily();
