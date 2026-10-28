<?php

namespace App\Http\Requests\Workspace\Server;

use App\Enums\MaintenanceOperation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Wartung starten (W-05): eine Art aus der festen Liste, ein Schema und auf Wunsch eine Tabelle darin. Ob es beides
 * gibt und ob der Treiber die Art kennt, prüft der Plan gegen den Katalog.
 */
class MaintenanceRequest extends ServerChangeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::enum(MaintenanceOperation::class)],
            'schema' => ['required', 'string', 'max:255'],
            'table' => ['nullable', 'string', 'max:255'],
            'database' => ['nullable', 'string', 'max:255'],
            ...$this->confirmationRules(),
        ];
    }

    public function operation(): MaintenanceOperation
    {
        return MaintenanceOperation::from((string) $this->validated('operation'));
    }

    public function table(): ?string
    {
        $table = $this->validated('table');

        return is_string($table) && $table !== '' ? $table : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'operation' => __('Wartung'),
            'schema' => __('Schema'),
            'table' => __('Tabelle'),
            'database' => __('Datenbank'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }
}
