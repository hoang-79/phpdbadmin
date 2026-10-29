<?php

namespace App\Http\Requests\Workspace\Schema;

use App\Services\Schema\ColumnSpec;
use App\Services\Schema\ForeignKeySpec;
use App\Services\Schema\TableDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Entwurf einer Tabelle (S-02 anlegen, S-03 ändern): Form und Grenzen der Felder. Ob Namen sicher sind, Typen zum
 * Treiber passen und Spalten, Tabellen und Indizes existieren, prüft danach der Plan gegen den Katalog — mit
 * Fehlern an genau dem Feld (columns.3.name).
 */
class TableDraftRequest extends SchemaChangeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->routeIs('workspace.table.*');

        return [
            'name' => $creating ? ['required', 'string', 'max:255'] : ['prohibited'],
            'columns' => [$creating ? 'required' : 'present', 'array', 'max:500'],
            'columns.*' => ['array'],
            'columns.*.original' => $creating ? ['prohibited'] : ['nullable', 'string', 'max:255'],
            'columns.*.drop' => ['boolean'],
            'columns.*.changed' => ['boolean'],
            'columns.*.name' => ['required', 'string', 'max:255'],
            'columns.*.type' => ['required', 'string', 'max:40'],
            'columns.*.custom' => ['nullable', 'string', 'max:255'],
            'columns.*.length' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'columns.*.precision' => ['nullable', 'integer', 'min:1', 'max:65'],
            'columns.*.scale' => ['nullable', 'integer', 'min:0', 'max:65'],
            'columns.*.values' => ['nullable', 'array', 'max:100'],
            'columns.*.values.*' => ['string', 'max:255'],
            'columns.*.nullable' => ['boolean'],
            'columns.*.default' => ['nullable', 'array'],
            'columns.*.default.kind' => ['nullable', Rule::in(ColumnSpec::DEFAULT_KINDS)],
            'columns.*.default.value' => ['nullable', 'string', 'max:1000'],
            'columns.*.autoIncrement' => ['boolean'],
            'columns.*.comment' => ['nullable', 'string', 'max:1024'],
            'primary' => $creating ? ['array', 'max:16'] : ['prohibited'],
            'primary.*' => ['string', 'max:255'],
            'indexes' => ['array', 'max:100'],
            'indexes.*' => ['array'],
            'indexes.*.original' => ['nullable', 'string', 'max:255'],
            'indexes.*.drop' => ['boolean'],
            'indexes.*.name' => ['nullable', 'string', 'max:255'],
            'indexes.*.columns' => ['array', 'max:16'],
            'indexes.*.columns.*' => ['string', 'max:255'],
            'indexes.*.unique' => ['boolean'],
            'foreignKeys' => ['array', 'max:100'],
            'foreignKeys.*' => ['array'],
            'foreignKeys.*.original' => ['nullable', 'array', 'max:16'],
            'foreignKeys.*.original.*' => ['string', 'max:255'],
            'foreignKeys.*.drop' => ['boolean'],
            'foreignKeys.*.name' => ['nullable', 'string', 'max:255'],
            'foreignKeys.*.columns' => ['array', 'max:16'],
            'foreignKeys.*.columns.*' => ['string', 'max:255'],
            'foreignKeys.*.table' => ['nullable', 'string', 'max:255'],
            'foreignKeys.*.references' => ['array', 'max:16'],
            'foreignKeys.*.references.*' => ['string', 'max:255'],
            'foreignKeys.*.onDelete' => ['nullable', Rule::in(ForeignKeySpec::RULES)],
            'foreignKeys.*.onUpdate' => ['nullable', Rule::in(ForeignKeySpec::RULES)],
            'comment' => ['nullable', 'string', 'max:2048'],
            'commentChanged' => ['boolean'],
            ...$this->commonRules(),
        ];
    }

    public function draft(?string $table = null): TableDraft
    {
        return TableDraft::fromArray($this->validated(), $table);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name der Tabelle'),
            'columns' => __('Spalten'),
            'columns.*.name' => __('Name der Spalte'),
            'columns.*.type' => __('Typ'),
            'columns.*.length' => __('Länge'),
            'columns.*.precision' => __('Genauigkeit'),
            'columns.*.scale' => __('Nachkommastellen'),
            'columns.*.comment' => __('Kommentar'),
            'comment' => __('Kommentar'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }
}
