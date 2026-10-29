<?php

namespace App\Services\Objects;

use App\Enums\ObjectKind;

/**
 * Was eine Änderung an einem Objekt ausführen wird (S-06): genau die Anweisungen, die laufen, ob in einer Transaktion,
 * Hinweise für die Vorschau — und bei MySQL/MariaDB, wo Löschen und Neuanlegen nicht rückrollbar ist, die bisherige
 * Definition, mit der phpdbadmin das Objekt wiederherstellt, wenn das Neuanlegen scheitert.
 */
final readonly class ObjectPlan
{
    /**
     * @param  'object.create'|'object.alter'|'object.drop'  $action
     * @param  string  $name  Name nach der Änderung
     * @param  string|null  $qualifier  Tabelle eines Triggers bzw. Parameter einer Routine (PostgreSQL)
     * @param  list<string>  $statements
     * @param  list<string>  $notes  übersetzt
     * @param  int|null  $dropIndex  Stelle des DROP, nach der `restore` gilt
     */
    public function __construct(
        public string $action,
        public ObjectKind $kind,
        public string $schema,
        public string $name,
        public ?string $qualifier,
        public array $statements,
        public bool $transactional,
        public array $notes = [],
        public ?string $restore = null,
        public ?int $dropIndex = null,
    ) {}

    public function target(): string
    {
        return $this->schema.'.'.$this->name;
    }

    /**
     * @return array{target: string, statements: list<string>, notes: list<string>, transactional: bool}
     */
    public function preview(): array
    {
        return [
            'target' => $this->target(),
            'statements' => array_map(fn (string $statement): string => rtrim(rtrim($statement), ';'), $this->statements),
            'notes' => $this->notes,
            'transactional' => $this->transactional,
        ];
    }

    /** Wiederherstellen, wenn eine Anweisung nach dem DROP scheitert. */
    public function restoresAfter(int $failedIndex): bool
    {
        return ! $this->transactional && $this->restore !== null && $this->dropIndex !== null && $failedIndex > $this->dropIndex;
    }
}
