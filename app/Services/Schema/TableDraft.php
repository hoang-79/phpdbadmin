<?php

namespace App\Services\Schema;

/**
 * Der Entwurf einer Tabelle aus dem Formular (S-02 anlegen, S-03 ändern). Beim Anlegen ist alles neu; beim Ändern
 * tragen bestehende Spalten, Indizes und Fremdschlüssel ihren bisherigen Namen (original) und ob sie wegfallen.
 */
final readonly class TableDraft
{
    /**
     * @param  list<ColumnSpec>  $columns  in der Reihenfolge des Formulars
     * @param  list<string>  $primary  Primärschlüssel beim Anlegen (ohne Auto-Increment-Spalte)
     * @param  list<IndexSpec>  $indexes
     * @param  list<ForeignKeySpec>  $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primary = [],
        public array $indexes = [],
        public array $foreignKeys = [],
        public ?string $comment = null,
        public bool $commentChanged = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input  geprüft durch den Form-Request
     */
    public static function fromArray(array $input, ?string $table = null): self
    {
        $list = fn (string $key): array => array_values(array_filter((array) ($input[$key] ?? []), 'is_array'));
        $comment = $input['comment'] ?? null;

        return new self(
            name: $table ?? (string) ($input['name'] ?? ''),
            columns: array_map(ColumnSpec::fromArray(...), $list('columns')),
            primary: array_values(array_map('strval', (array) ($input['primary'] ?? []))),
            indexes: array_map(IndexSpec::fromArray(...), $list('indexes')),
            foreignKeys: array_map(ForeignKeySpec::fromArray(...), $list('foreignKeys')),
            comment: is_string($comment) && $comment !== '' ? $comment : null,
            commentChanged: (bool) ($input['commentChanged'] ?? false),
        );
    }
}
