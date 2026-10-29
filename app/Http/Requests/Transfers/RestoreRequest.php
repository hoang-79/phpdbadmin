<?php

namespace App\Http\Requests\Transfers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Wiederherstellen aus einer hochgeladenen Sicherung (E-03): Datei, Ziel-Datenbank, die ausdrückliche Bestätigung,
 * dass Tabellen gleichen Namens ersetzt werden, und in Produktion der Name der Verbindung (prüft der Controller).
 */
class RestoreRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('phpdbadmin.transfers.max_upload_kb', 512 * 1024)],
            'database' => ['required', 'string', 'max:255'],
            'confirm' => ['accepted'],
            'confirmation' => ['nullable', 'string', 'max:255'],
            'db' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['confirm.accepted' => __('Bestätigen Sie, dass die Wiederherstellung Tabellen gleichen Namens ersetzt.')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => __('Datei'), 'database' => __('Datenbank'), 'confirmation' => __('Name der Verbindung')];
    }
}
