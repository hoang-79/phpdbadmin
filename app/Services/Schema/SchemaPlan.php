<?php

namespace App\Services\Schema;

use App\Enums\ApplyMode;
use App\Enums\DatabaseDriver;
use App\Support\SqlMask;

/**
 * Was eine Strukturänderung ausführen wird (S-02 bis S-05): die Anweisungen genau so, wie sie laufen (aus
 * Blueprint::toSql() bzw. gleich gebaut), das Verfahren je Treiber (ADR-003), Hinweise, was dabei an Daten verloren
 * geht, und welchen Namen man zur Bestätigung eintippen muss.
 */
final readonly class SchemaPlan
{
    /**
     * @param  string  $action  Aktion im Protokoll (schema.create, schema.alter …)
     * @param  string  $target  Ziel im Protokoll (shop.orders)
     * @param  list<string>  $statements
     * @param  list<string>  $notes  Hinweise in der Vorschau (übersetzt)
     * @param  list<string>  $losses  was verloren geht (übersetzt) — verlangt den Namen zur Bestätigung
     * @param  string|null  $confirmName  Name, den man vor dem Anwenden eintippen muss (Tabelle bzw. Schema)
     * @param  array<string, mixed>  $context  zusätzliche Angaben im Protokoll
     * @param  string|null  $file  SQLite: Datei, die angelegt wird (S-05) — dann gibt es keine Anweisungen
     */
    public function __construct(
        public string $action,
        public string $target,
        public DatabaseDriver $driver,
        public ApplyMode $mode,
        public array $statements,
        public array $notes = [],
        public array $losses = [],
        public ?string $confirmName = null,
        public array $context = [],
        public ?string $file = null,
    ) {}

    /**
     * Für die Oberfläche: Verfahren mit Erklärung, die Anweisungen mit Art, davor und danach, was das Verfahren selbst
     * ausführt (BEGIN, COMMIT, PRAGMA).
     *
     * @return array{target: string, mode: string, title: string, explanation: string, before: list<string>, after: list<string>, statements: list<array{sql: string, label: string|null}>, notes: list<string>, losses: list<string>, confirmName: string|null, file: string|null}
     */
    public function preview(): array
    {
        $framed = $this->statements !== [];

        return [
            'target' => $this->target,
            'mode' => $this->mode->value,
            'title' => $this->file !== null ? __('Neue SQLite-Datei') : $this->mode->title($this->driver),
            'explanation' => $this->file !== null ? $this->file : $this->mode->explanation($this->driver),
            'before' => $framed ? $this->mode->before() : [],
            'after' => $framed ? $this->mode->after() : [],
            'statements' => array_map(fn (string $sql): array => ['sql' => $sql, 'label' => StatementLabel::of($sql)], $this->statements),
            'notes' => $this->notes,
            'losses' => $this->losses,
            'confirmName' => $this->confirmName,
            'file' => $this->file,
        ];
    }

    /** Die Anweisungen für das Protokoll, eine je Zeile, Werte maskiert (B-04). */
    public function auditSql(): ?string
    {
        return $this->statements === [] ? null : SqlMask::mask(implode(";\n", $this->statements));
    }
}
