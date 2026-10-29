<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verlauf des SQL-Editors je Konto und Verbindung (Q-04, Datenmodell § 2.3); Frist 90 Tage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->longText('sql');
            $table->string('status', 12);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['user_id', 'connection_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_history');
    }
};
