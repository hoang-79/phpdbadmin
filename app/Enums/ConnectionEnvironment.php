<?php

namespace App\Enums;

/**
 * Umgebung einer Verbindung (V-04, G-03): Farbe, Wort und Schreibschutz-Vorgabe.
 */
enum ConnectionEnvironment: string
{
    case Local = 'local';
    case Test = 'test';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'lokal',
            self::Test => 'Test',
            self::Production => 'Produktion',
        };
    }

    /** Produktion ist beim Anlegen schreibgeschützt (V-04). */
    public function readOnlyByDefault(): bool
    {
        return $this === self::Production;
    }
}
