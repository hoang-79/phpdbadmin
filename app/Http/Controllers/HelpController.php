<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\Extension\Attributes\AttributesExtension;

/**
 * Kurze Hilfe in der Oberfläche (N-05): ein Text je Sprache unter `resources/help/{locale}.md`, Abschnitte mit festen
 * Ankern (`## Titel {#anker}`), auf die Knöpfe in der Oberfläche verlinken. Eigener, mitgelieferter Text — HTML darin
 * wird trotzdem entfernt.
 */
class HelpController extends Controller
{
    public function __invoke(): Response
    {
        $markdown = self::source(app()->getLocale());

        preg_match_all('/^## (.+?) \{#([a-z0-9-]+)\}$/m', $markdown, $matches, PREG_SET_ORDER);

        return Inertia::render('help/Show', [
            'html' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false], [new AttributesExtension]),
            'sections' => array_map(fn (array $match): array => ['id' => $match[2], 'title' => $match[1]], $matches),
        ]);
    }

    /** Hilfetext in der Sprache des Kontos, sonst Deutsch. */
    public static function source(string $locale): string
    {
        $path = resource_path("help/{$locale}.md");

        return (string) file_get_contents(is_file($path) ? $path : resource_path('help/de.md'));
    }
}
