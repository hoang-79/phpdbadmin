<?php

namespace App\Services\Schema;

/**
 * Ein Fremdschlüssel im Formular (S-02, S-03): Spalten dieser Tabelle → Tabelle und Spalten im selben Schema, mit
 * ON DELETE und ON UPDATE aus einer festen Liste. Ein bestehender wird über seine Spalten erkannt (original) — SQLite
 * kennt keine Namen für Fremdschlüssel.
 */
final readonly class ForeignKeySpec
{
    /** Regeln, die alle vier Treiber kennen (MySQL lehnt SET DEFAULT ab). */
    public const array RULES = ['no action', 'restrict', 'cascade', 'set null'];

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $references
     * @param  list<string>|null  $original  Spalten des bestehenden Fremdschlüssels
     */
    public function __construct(
        public ?string $name,
        public array $columns,
        public string $table,
        public array $references,
        public string $onDelete = 'no action',
        public string $onUpdate = 'no action',
        public ?array $original = null,
        public bool $drop = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $rule = fn (mixed $value): string => is_string($value) && in_array($value, self::RULES, true) ? $value : 'no action';
        $original = $input['original'] ?? null;

        return new self(
            name: is_string($input['name'] ?? null) && $input['name'] !== '' ? $input['name'] : null,
            columns: array_values(array_map('strval', (array) ($input['columns'] ?? []))),
            table: (string) ($input['table'] ?? ''),
            references: array_values(array_map('strval', (array) ($input['references'] ?? []))),
            onDelete: $rule($input['onDelete'] ?? null),
            onUpdate: $rule($input['onUpdate'] ?? null),
            original: is_array($original) && $original !== [] ? array_values(array_map('strval', $original)) : null,
            drop: (bool) ($input['drop'] ?? false),
        );
    }

    public function nameFor(string $table): string
    {
        return $this->name ?? SchemaName::conventional($table, $this->columns, 'foreign');
    }
}
