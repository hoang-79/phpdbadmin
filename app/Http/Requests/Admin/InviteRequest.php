<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Person einladen (A-06): Adresse ohne bestehendes Konto und eine Rolle. Nur Administratoren (Route).
 */
class InviteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.unique' => __('Für diese Adresse gibt es schon ein Konto.')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['role' => __('Rolle')];
    }

    public function role(): UserRole
    {
        return UserRole::from($this->string('role')->toString());
    }
}
