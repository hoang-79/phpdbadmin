<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fristen (Datenmodell § 1.8): Verlauf, Protokoll, Exporte — alles mit Prunable räumt der tägliche Lauf auf.
Schedule::command('model:prune')->daily();
