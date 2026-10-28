<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemerkte Ansicht je Konto und Tabelle (D-01, Datenmodell § 2.3): ausgeblendete Spalten und Breiten. Gespeichert
 * wird die Wahl, nie das Ergebnis. Filter und Sortierung stehen in der Adresse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->string('database')->nullable();
            $table->string('schema');
            $table->string('table');
            // Prüfsumme aus Datenbank, Schema und Tabelle: drei lange Namen sprengen sonst den Index von MySQL.
            $table->char('object_hash', 64);
            $table->json('columns')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['user_id', 'connection_id', 'object_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_views');
    }
};
