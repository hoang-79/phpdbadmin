<?php

namespace App\Services\Schema;

/**
 * Eine Spalte, wie sie im Formular «Tabelle anlegen» bzw. «Tabelle ändern» steht (S-02, S-03): Name, Typ aus der Liste
 * des Treibers (ColumnTypes) oder ein eigener Typ, Länge bzw. Genauigkeit, NULL, Vorgabe, Auto-Increment, Kommentar.
 * Beim Ändern zusätzlich der bisherige Name und ob die Spalte gelöscht oder ihre Definition geändert wird.
 *
 * Vorgabe: «none» keine, «null» ausdrücklich NULL, «value» ein Wert (als Zeichenkette gequotet), «expression» ein
 * Ausdruck aus einer kleinen Liste (CURRENT_TIMESTAMP) oder genau die bisherige Vorgabe der Spalte.
 */
final readonly class ColumnSpec
{
    public const array DEFAULT_KINDS = ['none', 'null', 'value', 'expression'];

    /**
     * @param  list<string>  $values  Auswahl einer enum-Spalte (MySQL, MariaDB)
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $custom = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public array $values = [],
        public bool $nullable = false,
        public string $defaultKind = 'none',
        public ?string $defaultValue = null,
        public bool $autoIncrement = false,
        public ?string $comment = null,
        public ?string $original = null,
        public bool $drop = false,
        public bool $changed = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input  geprüft durch den Form-Request
     */
    public static function fromArray(array $input): self
    {
        $default = is_array($input['default'] ?? null) ? $input['default'] : [];
        $text = fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $number = fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        return new self(
            name: (string) ($input['name'] ?? ''),
            type: (string) ($input['type'] ?? ''),
            custom: $text($input['custom'] ?? null),
            length: $number($input['length'] ?? null),
            precision: $number($input['precision'] ?? null),
            scale: $number($input['scale'] ?? null),
            values: array_values(array_map('strval', array_filter((array) ($input['values'] ?? []), fn (mixed $value): bool => is_string($value) && $value !== ''))),
            nullable: (bool) ($input['nullable'] ?? false),
            defaultKind: in_array($default['kind'] ?? 'none', self::DEFAULT_KINDS, true) ? (string) ($default['kind'] ?? 'none') : 'none',
            defaultValue: is_string($default['value'] ?? null) ? $default['value'] : null,
            autoIncrement: (bool) ($input['autoIncrement'] ?? false),
            comment: $text($input['comment'] ?? null),
            original: $text($input['original'] ?? null),
            drop: (bool) ($input['drop'] ?? false),
            changed: (bool) ($input['changed'] ?? false),
        );
    }

    /**
     * Für die Oberfläche (dieselben Schlüssel wie die Eingabe).
     *
     * @return array{name: string, type: string, custom: string|null, length: int|null, precision: int|null, scale: int|null, values: list<string>, nullable: bool, default: array{kind: string, value: string|null}, autoIncrement: bool, comment: string|null, original: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'custom' => $this->custom,
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'values' => $this->values,
            'nullable' => $this->nullable,
            'default' => ['kind' => $this->defaultKind, 'value' => $this->defaultValue],
            'autoIncrement' => $this->autoIncrement,
            'comment' => $this->comment,
            'original' => $this->original,
        ];
    }

    public function isNew(): bool
    {
        return $this->original === null;
    }

    public function isRenamed(): bool
    {
        return $this->original !== null && $this->original !== $this->name;
    }
}
