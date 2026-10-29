<?php

namespace App\Http\Requests\Transfers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datei für einen Import hochladen (E-02): CSV in eine Tabelle oder SQL-Datei in ein Schema. Die Grösse begrenzt
 * `phpdbadmin.transfers.max_upload_kb` (und PHP selbst über upload_max_filesize).
 */
class UploadImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['csv', 'sql'])],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('phpdbadmin.transfers.max_upload_kb', 512 * 1024),
                $this->input('format') === 'sql' ? 'extensions:sql,txt' : 'extensions:csv,tsv,txt',
            ],
            'schema' => ['required', 'string', 'max:255'],
            'table' => ['required_if:format,csv', 'nullable', 'string', 'max:255'],
            'db' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['format' => __('Format'), 'file' => __('Datei'), 'schema' => __('Schema'), 'table' => __('Tabelle')];
    }
}
