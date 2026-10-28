<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zielverbindungen und Rechte je Konto (Datenmodell § 2.2). Die Kennung ist eine ULID, weil sie in Adressen steht;
     * das Passwort liegt verschlüsselt (encrypted-Cast) und ist leer, wenn es nicht gespeichert werden soll (V-03).
     */
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('shared')->default(false);
            $table->string('name');
            $table->string('group')->nullable();
            $table->string('driver', 20);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('store_password')->default(true);
            $table->text('sqlite_path')->nullable();
            $table->json('options')->nullable();
            $table->string('environment', 20)->default('local');
            $table->boolean('read_only')->default(false);
            $table->string('server_version', 100)->nullable();
            $table->json('account_warnings')->nullable();
            $table->dateTime('last_tested_at')->nullable();
            $table->datetimes();

            $table->index('owner_id');
        });

        Schema::create('connection_user', function (Blueprint $table) {
            $table->foreignUlid('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 10)->default('read');
            $table->boolean('favorite')->default(false);
            $table->dateTime('last_opened_at')->nullable();

            $table->primary(['connection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_user');
        Schema::dropIfExists('connections');
    }
};
