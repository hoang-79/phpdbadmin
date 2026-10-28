<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Protokoll (A-09, B-04): Anmeldungen, Fehlversuche und später jede schreibende Aktion. Werte aus
     * Zieldatenbanken stehen nie im Klartext darin; IP-Adressen nur als Prüfsumme.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('target')->nullable();
            $table->longText('sql')->nullable();
            $table->unsignedBigInteger('affected_rows')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->dateTime('created_at');

            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
