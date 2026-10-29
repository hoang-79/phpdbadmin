<?php

namespace App\Http\Requests\Connections;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Verbindung testen» mit den Angaben aus dem Formular, noch vor dem Speichern (V-02). Beim Bearbeiten darf das
 * Passwortfeld leer bleiben; dann gilt das gespeicherte.
 */
class TestConnectionRequest extends FormRequest
{
    use ValidatesConnectionSettings;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->settingsRules(),
            'connection' => ['nullable', 'string', 'size:26'],
            'herd_source' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->settingsAttributes();
    }
}
