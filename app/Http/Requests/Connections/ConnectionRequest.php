<?php

namespace App\Http\Requests\Connections;

use App\Enums\ConnectionEnvironment;
use App\Models\DatabaseConnection;
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
     * Anlegen darf jedes Konto; Bearbeiten nur mit dem Recht «verwalten» (A-07). Eine fremde Verbindung kommt gar
     * nicht bis hier — die Routenbindung antwortet mit 404.
     */
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return ! $connection instanceof DatabaseConnection || ($this->user()?->can('manage', $connection) ?? false);
    }

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
            // Vorschlag aus Laravel Herd (V-07): Kennung der Quelle, aus der das Passwort kommen darf.
            'herd_source' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['group' => __('Gruppe'), 'environment' => __('Umgebung'), ...$this->settingsAttributes()];
    }
}
