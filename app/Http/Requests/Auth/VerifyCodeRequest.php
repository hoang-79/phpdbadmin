<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyCodeRequest extends FormRequest
{
    /**
     * Leerzeichen und Bindestriche aus dem Einfügen («123 456», «123-456») entfernen.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => preg_replace('/[\s-]+/', '', (string) $this->input('code')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:6'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }
}
