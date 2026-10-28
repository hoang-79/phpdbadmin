<?php

namespace App\Http\Requests\Connections;

use App\Enums\ConnectionEnvironment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Verbindung anlegen oder bearbeiten (V-01, V-03, V-05).
 */
class ConnectionRequest extends FormRequest
{
    use ValidatesConnectionSettings;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:255'],
            'environment' => ['required', Rule::enum(ConnectionEnvironment::class)],
            'read_only' => ['boolean'],
            'store_password' => ['boolean'],
            ...$this->settingsRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['group' => 'Gruppe', 'environment' => 'Umgebung', ...$this->settingsAttributes()];
    }
}
