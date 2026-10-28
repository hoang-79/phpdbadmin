<?php

namespace App\Dialects;

/**
 * Eine Anweisung, die phpdbadmin erst nach Vorschau und Bestätigung ausführt (W-02, W-04, W-05): der echte Text und
 * der Text für Vorschau und Protokoll, in dem Geheimnisse (Passwörter) maskiert sind.
 */
final readonly class PlannedStatement
{
    /** Ersatz für ein Passwort in Vorschau und Protokoll. */
    public const string MASK = "'••••••••'";

    /**
     * @param  list<string>  $objects  Tabellen, die die Anweisung betrifft (für das Ergebnis der Wartung)
     */
    public function __construct(public string $sql, private ?string $preview = null, public array $objects = []) {}

    public function preview(): string
    {
        return $this->preview ?? $this->sql;
    }
}
