<?php

namespace App\Services\Shape;

/**
 * Eine Spalte, wie Laravels Schema-Grammatik sie anlegt: Typ einer Blueprint-Methode (`bigInteger`, `string`,
 * `decimal` …) mit ihren Angaben. Grundlage für das Änderungsskript (S-08) und die Migration (S-09). Lässt sich ein
 * Typ nicht genau abbilden, steht der nächste passende hier und der Grund in `note`.
 */
final readonly class LaravelColumn
{
    /** Ganzzahlige Typen in aufsteigender Grösse. */
    public const array INTEGERS = ['tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger'];

    /**
     * @param  array{length?: int|null, fixed?: bool, total?: int, places?: int, precision?: int|null, allowed?: list<string>, unsigned?: bool, autoIncrement?: bool, subtype?: string|null, srid?: int|null}  $parameters
     */
    public function __construct(
        public string $type,
        public array $parameters = [],
        public ?string $note = null,
    ) {}

    public function isInteger(): bool
    {
        return in_array($this->type, self::INTEGERS, true);
    }

    public function unsigned(): bool
    {
        return (bool) ($this->parameters['unsigned'] ?? false);
    }

    public function autoIncrement(): bool
    {
        return (bool) ($this->parameters['autoIncrement'] ?? false);
    }

    /**
     * Gleicher Typ für eine Spalte, die per Fremdschlüssel auf diese zeigt: ohne Autoinkrement. MySQL verlangt für
     * Fremdschlüssel genau gleiche Ganzzahltypen (auch «unsigned»).
     */
    public function forReference(): self
    {
        $parameters = $this->parameters;
        unset($parameters['autoIncrement']);

        return new self($this->type, $parameters);
    }

    /**
     * @param  array{length?: int|null, fixed?: bool, total?: int, places?: int, precision?: int|null, allowed?: list<string>, unsigned?: bool, autoIncrement?: bool, subtype?: string|null, srid?: int|null}  $parameters
     */
    public function with(array $parameters): self
    {
        return new self($this->type, [...$this->parameters, ...$parameters], $this->note);
    }
}
