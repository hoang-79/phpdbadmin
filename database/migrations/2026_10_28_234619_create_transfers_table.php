<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Export und Import im Hintergrund (Datenmodell § 2.4, E-01, E-02, B-07). Die Kennung ist eine ULID, weil sie in
     * Adressen steht (Herunterladen); Dateien liegen unter storage/app/private/transfers und verschwinden mit dem
     * Datensatz nach der Frist (model:prune). Einstellungen, Ergebnis und Fehler als JSON, Texte darin als deutscher
     * Schlüssel mit Platzhaltern — übersetzt wird bei der Ausgabe.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('format', 10);
            $table->string('status', 10);
            $table->boolean('dry_run')->default(false);
            $table->boolean('in_background')->default(false);
            $table->string('target')->nullable();
            $table->json('options')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedBigInteger('rows')->default(0);
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->string('file_name')->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('expires_at');
            $table->datetimes();

            $table->index(['user_id', 'connection_id', 'created_at']);
            $table->index('expires_at');
        });
    }
};
