<?php

namespace App\Connections;

use App\Support\Translations;

/**
 * Ergebnis von «Verbindung testen»: Prüfschritte, Dauer, Version oder Fehler. Die Warnungen zum Konto bleiben hier
 * deutsch (so werden sie gespeichert) und werden erst in toArray() übersetzt.
 */
final readonly class ConnectionTestResult
{
    /**
     * @param  list<array{key: string, label: string, state: string, detail: string|null}>  $steps
     * @param  list<array{key: string, title: string, recommendation: string}>  $warnings
     */
    public function __construct(
        public bool $ok,
        public array $steps,
        public int $durationMs,
        public ?string $version = null,
        public ?ConnectionProblem $problem = null,
        public array $warnings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'steps' => $this->steps,
            'durationMs' => $this->durationMs,
            'version' => $this->version,
            'problem' => $this->problem?->toArray(),
            'warnings' => Translations::accountWarnings($this->warnings),
            'testedAt' => now()->toIso8601String(),
        ];
    }
}
