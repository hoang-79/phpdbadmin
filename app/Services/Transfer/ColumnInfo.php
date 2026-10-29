<?php

namespace App\Services\Transfer;

use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use Illuminate\Database\Connection;

/**
 * Was Export und Import über eine Spalte wissen müssen: Art (O-04), ob die Werte Binärdaten sind, ob die Datenbank
 * sie selbst füllt (generiert, Autowert, Vorgabe) und ob NULL erlaubt ist.
 */
final readonly class ColumnInfo
{
    public function __construct(
        public string $name,
        public string $type,
        public ColumnKind $kind,
        public bool $binary,
        public bool $nullable,
        public bool $hasDefault,
        public bool $autoIncrement,
        public bool $generated,
    ) {}

    /**
     * Spalten in der Reihenfolge der Tabelle bzw. Sicht.
     *
     * @return list<self>
     */
    public static function of(Connection $target, DatabaseDriver $driver, string $schema, string $table): array
    {
        return array_map(function (array $column) use ($driver): self {
            $typeName = strtolower((string) $column['type_name']);
            $kind = ColumnKind::of($typeName, (string) $column['type']);

            return new self(
                name: (string) $column['name'],
                type: (string) $column['type'],
                kind: $kind,
                // PostgreSQL bit/varbit kommen als Text «0101», nicht als Bytes.
                binary: $kind === ColumnKind::Binary && ! ($driver === DatabaseDriver::Postgres && str_starts_with($typeName, 'bit')),
                nullable: (bool) $column['nullable'],
                hasDefault: $column['default'] !== null,
                autoIncrement: (bool) $column['auto_increment'],
                generated: ($column['generation'] ?? null) !== null,
            );
        }, $target->getSchemaBuilder()->getColumns($schema.'.'.$table));
    }

    /** Muss der Import einen Wert liefern? */
    public function isRequired(): bool
    {
        return ! $this->nullable && ! $this->hasDefault && ! $this->autoIncrement && ! $this->generated;
    }

    /**
     * Für die Oberfläche: Typ und Merkmale.
     *
     * @return array{name: string, type: string, kind: string, required: bool, nullable: bool, generated: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'kind' => $this->kind->value,
            'required' => $this->isRequired(),
            'nullable' => $this->nullable,
            'generated' => $this->generated,
        ];
    }
}
