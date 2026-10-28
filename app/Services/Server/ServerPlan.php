<?php

namespace App\Services\Server;

use App\Dialects\PlannedStatement;

/**
 * Was eine ändernde Aktion der Server-Verwaltung ausführen wird (W-02, W-04, W-05): die Anweisungen, Hinweise für die
 * Vorschau und die Geheimnisse, die nie in Vorschau, Meldung oder Protokoll erscheinen dürfen.
 */
final readonly class ServerPlan
{
    /**
     * @param  string  $action  Aktion im Protokoll (server.kill, server.user.create …)
     * @param  string  $target  Ziel im Protokoll (#123, 'app'@'%', shop.orders …)
     * @param  list<PlannedStatement>  $statements
     * @param  list<string>  $notes  Hinweise in der Vorschau (übersetzt)
     * @param  list<string>  $secrets  Werte, die überall maskiert werden (Passwort roh und gequotet)
     * @param  array<string, mixed>  $context  zusätzliche Angaben im Protokoll
     */
    public function __construct(
        public string $action,
        public string $target,
        public array $statements,
        public array $notes = [],
        public array $secrets = [],
        public array $context = [],
    ) {}

    /**
     * Für die Oberfläche: genau die Anweisungen, die laufen werden — Geheimnisse maskiert.
     *
     * @return array{target: string, statements: list<string>, notes: list<string>}
     */
    public function preview(): array
    {
        return [
            'target' => $this->target,
            'statements' => array_map(fn (PlannedStatement $statement): string => $this->mask($statement->preview()), $this->statements),
            'notes' => $this->notes,
        ];
    }

    /** Die Anweisungen für das Protokoll, eine je Zeile, ohne Geheimnisse. */
    public function auditSql(): string
    {
        return implode(";\n", $this->preview()['statements']);
    }

    /** Geheimnisse in einem Text maskieren — auch in Meldungen des Servers, die Teile der Anweisung zitieren. */
    public function mask(string $text): string
    {
        $secrets = array_values(array_filter($this->secrets, fn (string $secret): bool => $secret !== ''));
        usort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return str_replace($secrets, '••••••••', $text);
    }
}
