<?php

namespace App\Services\Shape;

/**
 * Ergebnis eines Schemavergleichs (S-08): je Tabelle, was in der Quelle neu ist, was nur im Ziel steht und was sich
 * unterscheidet. Gleiche Tabellen stehen nur als Zahl da.
 */
final readonly class Comparison
{
    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    public const string CHANGED = 'changed';

    /**
     * @param  list<TableDifference>  $tables
     */
    public function __construct(
        public SchemaShape $source,
        public SchemaShape $target,
        public array $tables,
        public int $identical,
    ) {}

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }

    public function sameDriver(): bool
    {
        return $this->source->driver === $this->target->driver;
    }

    /**
     * @return array{tables: int, columns: int, indexes: int, foreignKeys: int}
     */
    public function counts(): array
    {
        $counts = ['tables' => 0, 'columns' => 0, 'indexes' => 0, 'foreignKeys' => 0];

        foreach ($this->tables as $table) {
            if ($table->status !== self::CHANGED) {
                $counts['tables']++;

                continue;
            }

            $counts['columns'] += count($table->columns);
            $counts['indexes'] += count($table->indexes);
            $counts['foreignKeys'] += count($table->foreignKeys);
        }

        return $counts;
    }
}
