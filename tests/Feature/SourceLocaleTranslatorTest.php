<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Mehrzahl in der Quellsprache (N-06): Deutsch hat keine Sprachdatei, der Schlüssel ist der Text — trans_choice darf
 * dafür nicht auf Englisch ausweichen.
 */
class SourceLocaleTranslatorTest extends TestCase
{
    private const string KEY = 'Wartung abgeschlossen, :count Befund.|Wartung abgeschlossen, :count Befunde.';

    public function test_german_plural_stays_german(): void
    {
        app()->setLocale('de');

        $this->assertSame('Wartung abgeschlossen, 1 Befund.', trans_choice(self::KEY, 1));
        $this->assertSame('Wartung abgeschlossen, 3 Befunde.', trans_choice(self::KEY, 3));
    }

    public function test_english_plural_comes_from_the_language_file(): void
    {
        app()->setLocale('en');

        $this->assertSame('Maintenance finished, 3 findings.', trans_choice(self::KEY, 3));
    }
}
