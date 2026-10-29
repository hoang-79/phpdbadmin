<?php

namespace App\Http\Requests\Transfers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Statistik einer Tabelle aktualisieren (E-06).
 */
class AnalyzeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'schema' => ['required', 'string', 'max:255'],
            'table' => ['required', 'string', 'max:255'],
            'db' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['schema' => __('Schema'), 'table' => __('Tabelle')];
    }
}
