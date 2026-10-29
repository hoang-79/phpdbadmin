<?php

namespace Tests\Feature\Http\Controllers;

use App\Http\Controllers\HelpController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Kurze Hilfe (N-05): Text in der Sprache des Kontos, feste Anker — und jeder Verweis aus der Oberfläche trifft in jeder
 * Sprache einen Abschnitt (die «Doku-Prüfung im Commit»).
 */
class HelpControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_the_help_in_the_language_of_the_account(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'en']))
            ->get(route('help'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('help/Show')
                ->where('sections.0', ['id' => 'anmelden', 'title' => 'Signing in'])
                ->where('html', fn (string $html): bool => str_contains($html, '<h2 id="bearbeiten">Changing data</h2>')));
    }

    public function test_guests_are_sent_to_the_sign_in(): void
    {
        $this->get(route('help'))->assertRedirect(route('login'));
    }

    public function test_html_in_the_source_is_removed(): void
    {
        $this->assertStringNotContainsString('<script', (string) preg_replace('/`[^`]*`/', '', HelpController::source('de')));
        $this->actingAs(User::factory()->create())
            ->get(route('help'))
            ->assertInertia(fn (Assert $page) => $page->where('html', fn (string $html): bool => ! str_contains($html, '<script') && str_contains($html, '<code>DROP</code>')));
    }

    public function test_every_link_from_the_interface_has_a_section_in_every_language(): void
    {
        $anchors = [];

        foreach ((new Finder)->in(resource_path('js'))->name('*.vue')->files() as $file) {
            preg_match_all('/<HelpLink[^>]*\banchor="([a-z0-9-]+)"/', $file->getContents(), $matches);
            array_push($anchors, ...$matches[1]);
        }

        $this->assertNotEmpty($anchors);

        $sectionsPerLanguage = [];

        foreach (['de', 'en'] as $locale) {
            preg_match_all('/^## .+? \{#([a-z0-9-]+)\}$/m', HelpController::source($locale), $matches);
            $sectionsPerLanguage[$locale] = $matches[1];

            foreach (array_unique($anchors) as $anchor) {
                $this->assertContains($anchor, $matches[1], "Abschnitt #{$anchor} fehlt in resources/help/{$locale}.md");
            }
        }

        $this->assertSame($sectionsPerLanguage['de'], $sectionsPerLanguage['en'], 'Beide Sprachen haben dieselben Abschnitte in derselben Reihenfolge.');
    }
}
