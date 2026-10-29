<?php

namespace App\Services\Shape;

/**
 * Ein Fremdschlüssel. Verglichen über Spalten und Ziel (Tabelle und Spalten); die Regeln ON UPDATE und ON DELETE
 * machen einen gleichen Schlüssel «geändert». RESTRICT und NO ACTION gelten als gleich — MariaDB meldet RESTRICT,
 * wo MySQL und PostgreSQL ohne Angabe NO ACTION melden.
 */
final readonly class ForeignKeyShape
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     */
    public function __construct(
        public ?string $name,
        public array $columns,
        public string $foreignTable,
        public array $foreignColumns,
        public string $onUpdate,
        public string $onDelete,
    ) {}

    /**
     * @param  array{name: string|null, columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}  $foreignKey
     */
    public static function fromIntrospection(array $foreignKey): self
    {
        return new self(
            $foreignKey['name'] !== null && $foreignKey['name'] !== '' ? (string) $foreignKey['name'] : null,
            array_map('strval', $foreignKey['columns']),
            (string) $foreignKey['foreign_table'],
            array_map('strval', $foreignKey['foreign_columns']),
            self::rule($foreignKey['on_update'] ?? null),
            self::rule($foreignKey['on_delete'] ?? null),
        );
    }

    public static function rule(?string $rule): string
    {
        $rule = strtolower(trim((string) $rule));

        return $rule === '' || $rule === 'restrict' ? 'no action' : $rule;
    }

    public function signature(): string
    {
        return implode(',', array_map('strtolower', $this->columns)).'>'.strtolower($this->foreignTable).'('.implode(',', array_map('strtolower', $this->foreignColumns)).')';
    }

    public function sameRules(self $other): bool
    {
        return $this->onUpdate === $other->onUpdate && $this->onDelete === $other->onDelete;
    }

    /** Für Listen: «(kunde_id) → kunden (id) ON DELETE CASCADE». */
    public function summary(): string
    {
        $text = '('.implode(', ', $this->columns).') → '.$this->foreignTable.' ('.implode(', ', $this->foreignColumns).')';

        if ($this->onDelete !== 'no action') {
            $text .= ' ON DELETE '.strtoupper($this->onDelete);
        }

        if ($this->onUpdate !== 'no action') {
            $text .= ' ON UPDATE '.strtoupper($this->onUpdate);
        }

        return $text;
    }
}
