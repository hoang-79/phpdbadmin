<?php

namespace App\Http\Requests\Workspace\Server;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Prozess beenden (W-02): nur die laufende Anweisung («query») oder die ganze Sitzung («session»). Die Kennung kommt
 * als ganze Zahl aus der Adresse.
 */
class EndProcessRequest extends ServerChangeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['query', 'session'])],
            ...$this->confirmationRules(),
        ];
    }

    public function wholeSession(): bool
    {
        return $this->validated('mode') === 'session';
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['mode' => __('Art'), 'confirmation' => __('Name der Verbindung')];
    }
}
