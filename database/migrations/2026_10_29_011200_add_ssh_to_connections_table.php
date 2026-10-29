<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SSH-Tunnel je Verbindung (V-08, Datenmodell § 2.2): Host, Port und Benutzer des SSH-Servers, der private
     * Schlüssel verschlüsselt (encrypted-Cast) oder als Pfad in einem erlaubten Ordner, die Passphrase verschlüsselt.
     * Ohne `ssh_host` verbindet phpdbadmin direkt.
     *
     * Ohne down(): Der Wächter für Migrationen lässt kein Entfernen von Spalten zu (Protokoll TB 11).
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('ssh_host')->nullable()->after('options');
            $table->unsignedInteger('ssh_port')->nullable()->after('ssh_host');
            $table->string('ssh_username')->nullable()->after('ssh_port');
            $table->text('ssh_key')->nullable()->after('ssh_username');
            $table->text('ssh_key_path')->nullable()->after('ssh_key');
            $table->text('ssh_passphrase')->nullable()->after('ssh_key_path');
        });
    }
};
