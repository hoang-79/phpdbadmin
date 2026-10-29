<?php

namespace App\Services\Shape;

/**
 * Änderungsskript im Dialekt des Ziels (S-08): Anweisungen mit einer Überschrift je Schritt und Hinweise, wo sich
 * etwas nicht genau übertragen lässt. Ausgeführt wird es nie automatisch — es wird kopiert, heruntergeladen oder im
 * SQL-Editor geöffnet.
 */
final class ChangeScript
{
    /** @var list<array{heading: string, statements: list<string>}> */
    private array $steps = [];

    /** @var list<string> */
    private array $notes = [];

    /**
     * @param  list<string>  $header  Kommentarzeilen am Anfang (ohne «-- »)
     */
    public function __construct(private readonly array $header = []) {}

    /**
     * @param  list<string>  $statements
     */
    public function add(string $heading, array $statements): void
    {
        if ($statements !== []) {
            $this->steps[] = ['heading' => $heading, 'statements' => $statements];
        }
    }

    public function note(string $note): void
    {
        if (! in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return array_merge(...array_map(fn (array $step): array => $step['statements'], $this->steps ?: [['statements' => []]]));
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    public function isEmpty(): bool
    {
        return $this->statements() === [];
    }

    /**
     * Das Skript als Text: Kopf, Hinweise, Schritte mit Überschrift, jede Anweisung mit Strichpunkt.
     */
    public function text(): string
    {
        $lines = array_map(fn (string $line): string => '-- '.$line, $this->header);

        foreach ($this->notes as $note) {
            $lines[] = '-- ! '.$note;
        }

        foreach ($this->steps as $step) {
            $lines[] = '';
            $lines[] = '-- '.$step['heading'];

            foreach ($step['statements'] as $statement) {
                $lines[] = rtrim($statement, "; \n").';';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
