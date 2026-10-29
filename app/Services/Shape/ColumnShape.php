<?php

namespace App\Services\Shape;

use App\Enums\ColumnKind;

/**
 * Eine Spalte, so gelesen, dass sie sich mit einer Spalte eines anderen Treibers vergleichen und dort neu anlegen
 * lässt: Typ im Original, Art (O-04), NULL, Vorgabe, Autoinkrement und die passende Spalte in Laravel.
 */
final readonly class ColumnShape
{
    /**
     * @param  array{type: string, expression: string}|null  $generated
     */
    public function __construct(
        public string $name,
        public string $type,
        public ColumnKind $kind,
        public bool $nullable,
        public ?DefaultValue $default,
        public bool $autoIncrement,
        public LaravelColumn $laravel,
        public ?string $comment = null,
        public ?array $generated = null,
    ) {}

    public function withLaravel(LaravelColumn $laravel): self
    {
        return new self($this->name, $this->type, $this->kind, $this->nullable, $this->default, $this->autoIncrement, $laravel, $this->comment, $this->generated);
    }

    /** Länge bei Texten (varchar, char), wo der Treiber sie meldet — SQLite nicht. */
    public function length(): ?int
    {
        if (! in_array($this->laravel->type, ['string', 'char'], true)) {
            return null;
        }

        return preg_match('/\((\d+)\)/', $this->type, $match) === 1 ? (int) $match[1] : null;
    }

    /** Für Listen: «varchar(100) · NULL · Vorgabe 'x'». */
    public function summary(): string
    {
        $parts = [$this->type];

        if ($this->autoIncrement) {
            $parts[] = 'auto_increment';
        }

        $parts[] = $this->nullable ? 'NULL' : 'NOT NULL';

        if ($this->default !== null) {
            $parts[] = 'DEFAULT '.$this->default->display();
        }

        return implode(' · ', $parts);
    }
}
