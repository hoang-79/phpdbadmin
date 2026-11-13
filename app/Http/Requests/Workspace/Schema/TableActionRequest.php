<?php

namespace App\Http\Requests\Workspace\Schema;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Tabelle umbenennen, kopieren, leeren oder löschen (S-04), verschieben (S-11), Optionen ändern (S-10). Leeren und Löschen laufen nur mit dem eingetippten Namen
 * der Tabelle — das prüft SchemaChanges vor der ersten Anweisung.
 */
class TableActionRequest extends SchemaChangeRequest
{
    public const array OPERATIONS = ['rename', 'copy', 'truncate', 'drop', 'options'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(self::OPERATIONS)],
            'to' => ['exclude_unless:operation,rename,copy', 'required', 'string', 'max:255'],
            'to_schema' => ['exclude_unless:operation,rename,copy', 'nullable', 'string', 'max:255'],
            'with_data' => ['boolean'],
            // Tabellenoptionen (S-10): nur, was sich ändern soll; leer heisst unverändert
            'engine' => ['exclude_unless:operation,options', 'nullable', 'string', 'max:64'],
            'collation' => ['exclude_unless:operation,options', 'nullable', 'string', 'max:64'],
            'convert' => ['exclude_unless:operation,options', 'boolean'],
            'auto_increment' => ['exclude_unless:operation,options', 'nullable', 'integer', 'min:1', 'max:18446744073709551615'],
            ...$this->commonRules(),
        ];
    }

    public function operation(): string
    {
        return (string) $this->validated('operation');
    }

    public function to(): string
    {
        return (string) $this->validated('to');
    }

    /** Ziel beim Verschieben und Kopieren (S-11) — ohne Angabe dieselbe Datenbank bzw. dasselbe Schema. */
    public function toSchema(string $schema): string
    {
        $value = $this->validated('to_schema');

        return is_string($value) && $value !== '' ? $value : $schema;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'operation' => __('Aktion'),
            'to' => __('Neuer Name'),
            'to_schema' => __('Ziel'),
            'engine' => __('Engine'),
            'collation' => __('Sortierung'),
            'auto_increment' => __('Nächster Wert des Zählers'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }
}
