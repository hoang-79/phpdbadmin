<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vorher- und Nachher-Werte angewendeter Datenänderungen für «Rückgängig» (D-09, Datenmodell § 2.4): je Zeile ein
 * Eintrag, gruppiert über den Protokolleintrag. Die Werte stehen nur hier (mit Frist), nie im Protokoll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_log_id')->constrained('audit_log')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->string('database')->nullable();
            $table->string('schema');
            $table->string('target');
            $table->unsignedInteger('position');
            $table->string('operation', 10);
            $table->json('key');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('undone_at')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['audit_log_id', 'position']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_snapshots');
    }
};
