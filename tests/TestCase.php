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
    }
}
