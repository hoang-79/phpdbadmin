<?php

namespace App\Services\Schema;

/**
 * Ein Index im Formular (S-02, S-03): neu mit Spalten und «eindeutig», oder ein bestehender (original), der gelöscht
 * wird. Ohne Namen vergibt phpdbadmin einen nach Laravels Muster.
 */
final readonly class IndexSpec
{
    /**
     * @param  list<string>  $columns  Spalten mit ihrem neuen Namen
     */
    public function __construct(
        public ?string $name,
        public array $columns,
        public bool $unique = false,
        public ?string $original = null,
        public bool $drop = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            name: is_string($input['name'] ?? null) && $input['name'] !== '' ? $input['name'] : null,
            columns: array_values(array_map('strval', (array) ($input['columns'] ?? []))),
            unique: (bool) ($input['unique'] ?? false),
            original: is_string($input['original'] ?? null) && $input['original'] !== '' ? $input['original'] : null,
            drop: (bool) ($input['drop'] ?? false),
        );
    }

    public function nameFor(string $table): string
    {
        return $this->name ?? SchemaName::conventional($table, $this->columns, $this->unique ? 'unique' : 'index');
    }
}
