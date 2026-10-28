<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Konten ohne Passwort (A-01): Anmeldung nur per Code oder Passkey; keine Spalten für Passwort, Zwei-Faktor
        // und «angemeldet bleiben» (Datenmodell § 1). Darstellung je Konto (G-04, G-05).
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role', 20)->default('member');
            $table->string('locale', 10)->default('de');
            $table->string('theme', 10)->default('system');
            $table->string('density', 10)->default('default');
            $table->string('timezone', 64)->default('Europe/Zurich');
            $table->dateTime('last_login_at')->nullable();
            $table->datetimes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
