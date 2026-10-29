<?php

namespace App\Http\Requests\Connections;

use App\Enums\ConnectionPermission;
use App\Models\DatabaseConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teilen speichern (V-09): `shared` und je Konto ein Recht oder null («kein Zugriff»). Nur Administratoren.
 */
class SharingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $connection instanceof DatabaseConnection && ($this->user()?->can('share', $connection) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shared' => ['required', 'boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['nullable', Rule::enum(ConnectionPermission::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['shared' => __('Teilen'), 'permissions.*' => __('Recht')];
    }

    /**
     * Recht je Konto-Kennung; Schlüssel, die keine Kennung sind, fallen weg.
     *
     * @return array<int, string|null>
     */
    public function permissions(): array
    {
        $permissions = [];

        foreach ((array) $this->validated('permissions', []) as $accountId => $permission) {
            if (is_int($accountId)) {
                $permissions[$accountId] = is_string($permission) ? $permission : null;
            }
        }

        return $permissions;
    }
}
