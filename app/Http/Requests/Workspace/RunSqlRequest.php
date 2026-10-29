<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Auftrag an den SQL-Editor (Q-02): Text, was davon (Anweisung an der Schreibmarke oder alles), Lauf-Kennung für das
 * Abbrechen, Zeilen je Ergebnis, Bestätigung gefährlicher Anweisungen (Q-07).
 */
class RunSqlRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sql' => ['required', 'string', 'max:1000000'],
            'mode' => ['required', Rule::in(['statement', 'all'])],
            'cursor' => ['nullable', 'integer', 'min:0'],
            'run' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('phpdbadmin.editor.max_row_limit', 10000)],
            'schema' => ['nullable', 'string', 'max:255'],
            'confirmed' => ['boolean'],
            'confirmation' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sql' => __('SQL'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }
}
