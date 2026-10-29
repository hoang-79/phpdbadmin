<?php

namespace App\Http\Requests\Workspace\Schema;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Tabelle umbenennen, kopieren, leeren oder löschen (S-04). Leeren und Löschen laufen nur mit dem eingetippten Namen
 * der Tabelle — das prüft SchemaChanges vor der ersten Anweisung.
 */
class TableActionRequest extends SchemaChangeRequest
{
    public const array OPERATIONS = ['rename', 'copy', 'truncate', 'drop'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(self::OPERATIONS)],
            'to' => ['exclude_unless:operation,rename,copy', 'required', 'string', 'max:255'],
            'with_data' => ['boolean'],
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'operation' => __('Aktion'),
            'to' => __('Neuer Name'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }
}
