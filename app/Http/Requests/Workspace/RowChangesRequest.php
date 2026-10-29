<?php

namespace App\Http\Requests\Workspace;

use App\Services\Data\RowEditor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Gesammelte Änderungen an Zeilen (D-04): einfügen, ändern, löschen — höchstens 1'000 auf einmal. Was die Tabelle
 * erlaubt (Spalten, Primärschlüssel, JSON), prüft der RowEditor gegen die echte Tabelle.
 */
class RowChangesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:'.RowEditor::MAX_CHANGES],
            'changes.*.op' => ['required', 'in:insert,update,delete'],
            'changes.*.key' => ['nullable', 'array', 'max:16'],
            'changes.*.key.*' => ['nullable', 'string', 'max:1000'],
            'changes.*.values' => ['nullable', 'array', 'max:500'],
            'confirmation' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Änderungen in der Form, die der RowEditor erwartet; Werte nur als Text, Zahl, Wahrheitswert oder NULL.
     *
     * @return list<array{op: 'insert'|'update'|'delete', key: list<string|null>|null, values: array<string, string|int|float|bool|null>|null}>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ((array) $this->validated('changes') as $change) {
            $values = null;

            if (is_array($change['values'] ?? null)) {
                $values = [];
                foreach ($change['values'] as $column => $value) {
                    if (is_string($column) && ($value === null || is_scalar($value))) {
                        $values[$column] = $value;
                    }
                }
            }

            $changes[] = [
                'op' => $change['op'],
                'key' => isset($change['key']) ? array_values(array_map(fn (mixed $part): ?string => $part === null ? null : (string) $part, (array) $change['key'])) : null,
                'values' => $values,
            ];
        }

        return $changes;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['confirmation' => __('Name der Verbindung')];
    }
}
