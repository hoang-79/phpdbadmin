<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gesperrte Konten (Verwaltung, Wireframe 14): Zeitpunkt der Sperre. Ein gesperrtes Konto kann sich nicht
     * anmelden; laufende Sitzungen enden mit der nächsten Anfrage. Ohne down(): die Spalte trägt Daten der
     * Verwaltung, ein Zurückrollen soll sie nicht still löschen.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('disabled_at')->nullable();
        });
    }
};
