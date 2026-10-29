<?php

namespace App\Http\Requests\Transfers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sicherung starten (E-03): welche Datenbank, bei PostgreSQL das Format, bei MySQL/MariaDB mit oder ohne Routinen,
 * Trigger und Ereignisse. Ob es die Datenbank gibt, prüft der Controller gegen den Katalog.
 */
class BackupRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'database' => ['required', 'string', 'max:255'],
            'pg_format' => ['nullable', Rule::in(['custom', 'plain'])],
            'objects' => ['boolean'],
            'db' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['database' => __('Datenbank'), 'pg_format' => __('Format')];
    }
}
