<?php

namespace App\Http\Requests\Workspace\Schema;

use App\Enums\DatabaseDriver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Datenbank (MySQL, MariaDB) bzw. Schema (PostgreSQL) anlegen oder löschen, bei PostgreSQL mit «alle Datenbanken» auch
 * eine ganze Datenbank löschen, bei SQLite eine neue Datei in einem erlaubten Ordner (S-05). Löschen nur mit dem
 * eingetippten Namen.
 */
class SchemaRequest extends SchemaChangeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isSqlite = $this->targetConnection()->driver === DatabaseDriver::Sqlite;

        return [
            // drop-database: ganze Datenbank bei PostgreSQL mit «alle Datenbanken»; drop: Datenbank (MySQL, MariaDB) bzw. Schema.
            'operation' => ['required', $isSqlite ? Rule::in(['create']) : Rule::in(['create', 'drop', 'drop-database'])],
            'name' => $isSqlite ? ['prohibited'] : ['required', 'string', 'max:255'],
            'folder' => $isSqlite ? ['required', 'string', 'max:1024'] : ['prohibited'],
            'file' => $isSqlite ? ['required', 'string', 'max:255'] : ['prohibited'],
            ...$this->commonRules(),
        ];
    }

    public function operation(): string
    {
        return (string) $this->validated('operation');
    }

    public function text(string $key): string
    {
        return (string) $this->validated($key);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'operation.in' => __('SQLite-Dateien löscht phpdbadmin nicht — das geht im Dateisystem.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'operation' => __('Aktion'),
            'name' => __('Name'),
            'folder' => __('Ordner'),
            'file' => __('Dateiname'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }
}
