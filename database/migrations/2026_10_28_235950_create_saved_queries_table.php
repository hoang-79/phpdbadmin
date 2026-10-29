<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gespeicherte Abfragen mit Namen (Q-04, Datenmodell § 2.3), je Konto und Verbindung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connection_id')->nullable()->constrained('connections')->cascadeOnDelete();
            $table->string('name', 120);
            $table->longText('sql');
            $table->boolean('shared')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['user_id', 'connection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_queries');
    }
};
