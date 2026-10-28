<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Deutsch und Englisch vollständig (N-06, O-06): jeder Text, den der Code übersetzt, steht in `lang/en.json` —
 * ohne Rückfall auf den deutschen Schlüssel —, und die Datei trägt nichts, was der Code nicht mehr braucht.
 */
class LanguageFilesTest extends TestCase
{
    public function test_every_translated_text_has_an_english_line(): void
    {
        $english = $this->english();
        $missing = array_values(array_diff(array_keys($this->usedKeys()), array_keys($english)));

        $this->assertSame([], $missing, 'In lang/en.json fehlen: '.implode(' · ', $missing));
        $this->assertSame([], array_keys(array_filter($english, fn (mixed $line): bool => ! is_string($line) || trim($line) === '')), 'Leere Zeilen in lang/en.json');
    }

    public function test_english_file_carries_no_stale_lines(): void
    {
        $stale = array_values(array_diff(array_keys($this->english()), array_keys($this->usedKeys())));

        $this->assertSame([], $stale, 'Nicht mehr verwendet: '.implode(' · ', $stale));
    }

    public function test_placeholders_match_between_german_and_english(): void
    {
        $mismatched = [];

        foreach ($this->english() as $key => $line) {
            if ($this->placeholders($key) !== $this->placeholders((string) $line)) {
                $mismatched[] = $key;
            }
        }

        $this->assertSame([], $mismatched);
    }

    public function test_keys_are_literal_texts(): void
    {
        $dynamic = array_values(array_filter(array_keys($this->usedKeys()), fn (string $key): bool => str_contains($key, '${')));

        $this->assertSame([], $dynamic, 'Schlüssel mit ${…} lassen sich nicht übersetzen');
    }

    public function test_validation_field_names_exist_in_both_languages(): void
    {
        $german = require lang_path('de/validation.php');
        $english = require lang_path('en/validation.php');

        $this->assertEqualsCanonicalizing(array_keys($german['attributes']), array_keys($english['attributes']));
    }

    /**
     * @return array<string, true>
     */
    private function usedKeys(): array
    {
        $keys = [];
        $patterns = [
            // PHP: __('…'), trans('…'), trans_choice('…'), @lang('…')
            'php' => '/(?<![\w>$:])(?:__|trans|trans_choice|@lang)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
            // Vue und TypeScript: t('…'), tc('…')
            'js' => '/(?<![\w.$])(?:t|tc)\(\s*([\'"`])((?:\\\\.|(?!\1).)*)\1/s',
        ];

        $files = Finder::create()->files()->in([app_path(), base_path('bootstrap'), base_path('routes'), resource_path('views'), resource_path('js')])
            ->name(['*.php', '*.vue', '*.ts'])->notPath(['actions', 'routes', 'wayfinder', 'cache']);

        foreach ($files as $file) {
            $pattern = $file->getExtension() === 'php' ? $patterns['php'] : $patterns['js'];
            preg_match_all($pattern, $this->withoutComments($file->getContents()), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $keys[stripcslashes($match[2])] = true;
            }
        }

        return $keys;
    }

    /**
     * Beispiele in Kommentaren (`t('Verbindungen')` in einer Beschreibung) sind keine Verwendung.
     */
    private function withoutComments(string $source): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m', '~<!--.*?-->~s'], '', $source);
    }

    /**
     * @return array<string, mixed>
     */
    private function english(): array
    {
        return json_decode((string) file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $line): array
    {
        preg_match_all('/:([a-z_]+)/i', $line, $matches);
        $names = array_unique(array_map('strtolower', $matches[1]));
        sort($names);

        return $names;
    }
}
