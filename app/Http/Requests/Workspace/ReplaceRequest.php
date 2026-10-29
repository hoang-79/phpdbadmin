<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Suchen und Ersetzen in einer Tabelle (D-08). Such- und Ersatztext kommen wörtlich an — auch Leerzeichen am Rand
 * und leerer Ersatz (bootstrap/app.php nimmt diese Adressen vom Kürzen aus). Ob die Spalten taugen, prüft
 * SearchReplace gegen die echte Tabelle.
 */
class ReplaceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'columns' => ['required', 'array', 'min:1', 'max:200'],
            'columns.*' => ['required', 'string', 'max:255'],
            'search' => ['present', 'string', 'min:1', 'max:1000'],
            'replace' => ['present', 'nullable', 'string', 'max:10000'],
            'match_case' => ['boolean'],
            'whole_value' => ['boolean'],
            'without_undo' => ['boolean'],
            'confirmation' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{columns: list<string>, search: string, replace: string, matchCase: bool, wholeValue: bool}
     */
    public function replacement(): array
    {
        return [
            'columns' => array_values(array_unique(array_map(strval(...), (array) $this->validated('columns')))),
            'search' => (string) $this->validated('search'),
            'replace' => (string) ($this->validated('replace') ?? ''),
            'matchCase' => $this->boolean('match_case'),
            'wholeValue' => $this->boolean('whole_value'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'columns' => __('Spalten'),
            'search' => __('Suchen nach'),
            'replace' => __('Ersetzen durch'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }
}
