<?php

namespace App\Http\Requests\Transfers;

use App\Services\Transfer\Csv;
use App\Services\Transfer\CsvReader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Wie die CSV-Datei zu lesen ist (E-02): Trennzeichen, Kodierung, Kopfzeile — danach neu analysiert.
 */
class UpdateImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'delimiter' => ['required', Rule::in(array_values(Csv::DELIMITERS))],
            'encoding' => ['required', Rule::in([CsvReader::UTF8, CsvReader::WINDOWS_1252])],
            'header' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['delimiter' => __('Trennzeichen'), 'encoding' => __('Kodierung'), 'header' => __('Kopfzeile')];
    }

    public function delimiter(): string
    {
        return (string) (array_search($this->input('delimiter'), Csv::DELIMITERS, true) ?: ',');
    }
}
