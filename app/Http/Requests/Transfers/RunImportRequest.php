<?php

namespace App\Http\Requests\Transfers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Probelauf oder Import starten (E-02). Die Zuordnung nennt je Spalte der Datei eine Spalte der Tabelle oder nichts;
 * ob es die Spalten gibt, prüft der Controller gegen die Tabelle. Auf Produktion verlangt der echte Lauf den Namen
 * der Verbindung (wie Wireframe 06).
 */
class RunImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dry_run' => ['required', 'boolean'],
            'mapping' => ['nullable', 'array', 'max:1000'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'on_error' => ['nullable', Rule::in(['skip', 'abort'])],
            'analyze' => ['boolean'],
            'confirmation' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['mapping' => __('Zuordnung'), 'confirmation' => __('Name der Verbindung')];
    }
}
