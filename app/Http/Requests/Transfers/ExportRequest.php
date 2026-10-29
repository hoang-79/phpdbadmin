<?php

namespace App\Http\Requests\Transfers;

use App\Enums\TransferFormat;
use App\Services\Transfer\Csv;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Export starten (E-01, E-04, Wireframe 11). Ob Schema und Tabelle existieren, prüft der Controller gegen den
 * Katalog. CSV gibt es nur für eine Tabelle — eine ganze Datenbank wären mehrere Dateien; Excel legt je Tabelle ein
 * Blatt an.
 */
class ExportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(TransferFormat::EXPORTS)],
            'scope' => ['required', Rule::in(['table', 'schema']), Rule::when($this->input('format') === TransferFormat::Csv->value, [Rule::in(['table'])])],
            'schema' => ['required', 'string', 'max:255'],
            'table' => ['required_if:scope,table', 'nullable', 'string', 'max:255'],
            'db' => ['nullable', 'string', 'max:255'],
            'delimiter' => ['nullable', Rule::in(array_values(Csv::DELIMITERS))],
            'bom' => ['boolean'],
            'header' => ['boolean'],
            'binary' => ['nullable', Rule::in(['base64', 'skip'])],
            'content' => ['nullable', Rule::in(['both', 'structure', 'data'])],
            'drop' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['scope.in' => __('CSV gibt es nur für eine Tabelle — für eine ganze Datenbank wählen Sie JSON oder SQL.')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'format' => __('Format'),
            'scope' => __('Umfang'),
            'schema' => __('Schema'),
            'table' => __('Tabelle'),
            'delimiter' => __('Trennzeichen'),
            'binary' => __('Binärspalten'),
            'content' => __('Inhalt'),
        ];
    }

    /** Das gewählte Trennzeichen als Zeichen (im Formular steht sein Name). */
    public function delimiter(): string
    {
        $name = $this->input('delimiter');

        return (string) (array_search($name, Csv::DELIMITERS, true) ?: ',');
    }
}
