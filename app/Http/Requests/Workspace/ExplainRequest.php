<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EXPLAIN im SQL-Editor (Q-06): Text und Schreibmarke (die Anweisung darunter) bzw. eine Auswahl, Schema, ANALYZE nur
 * auf Wunsch.
 */
class ExplainRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sql' => ['required', 'string', 'max:1000000'],
            'cursor' => ['nullable', 'integer', 'min:0'],
            'selection' => ['boolean'],
            'schema' => ['nullable', 'string', 'max:255'],
            'analyze' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['sql' => __('SQL')];
    }
}
