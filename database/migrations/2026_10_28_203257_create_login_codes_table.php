<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anmeldecodes per Mail (A-02): sechs Ziffern, zehn Minuten, einmalig. Gespeichert wird nur eine Prüfsumme
     * (HMAC mit APP_KEY) von Code und Adresse — der Datensatz verrät weder das eine noch das andere.
     */
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 20)->default('login');
            $table->string('email_hash', 64)->index();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->dateTime('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_codes');
    }
};
