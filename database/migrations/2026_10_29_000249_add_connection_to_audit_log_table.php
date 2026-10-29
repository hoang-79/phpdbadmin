<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Protokoll je Verbindung (B-04): Kennung der Verbindung und die Namen von Konto und Verbindung zum Zeitpunkt
     * des Eintrags — beide bleiben lesbar, wenn Konto oder Verbindung später entfernt werden (Datenmodell § 4).
     * Ohne Fremdschlüssel auf `connections`: ein Eintrag überlebt seine Verbindung. Indizes für die Filter der
     * Ansicht und den täglichen Aufräumlauf. Ohne down(): Protokolldaten sollen nicht still verschwinden.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->ulid('connection_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('connection_name')->nullable();

            $table->index(['connection_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }
};
