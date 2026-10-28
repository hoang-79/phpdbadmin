<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

// Feasibility-Proben F-3 und F-4, nur lokal (dokumentationen/02-feasibility).
if (app()->environment('local')) {
    require __DIR__.'/probe.php';
}
