<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mails, die nach der Antwort hinausgehen (defer), sofort ausführen.
        $this->withoutDefer();

        // Tests prüfen Server und Inertia-Props, nicht den Build der Oberfläche.
        $this->withoutVite();

        // Symfony schickt sonst «en-us» mit — die Tests fragen wie das Hauptpublikum an (N-06).
        $this->withHeader('Accept-Language', 'de-CH,de;q=0.9');
    }
}
