<?php

namespace App\Support;

/**
 * Deutsch und Englisch (N-06, Sie-Form). Der deutsche Text ist der Schlüssel im Code (`__('Verbindungen')`,
 * `t('Verbindungen')` in Vue); `lang/en.json` liefert Englisch. Ein Test hält beide Seiten vollständig.
 */
final class Translations
{
    /** Erste Sprache = Rückfall, wenn der Browser keine der beiden nennt. */
    public const array LOCALES = ['de', 'en'];

    public static function isSupported(?string $locale): bool
    {
        return in_array($locale, self::LOCALES, true);
    }

    /**
     * Was die Oberfläche zum Übersetzen braucht: auf Deutsch nichts (die Schlüssel sind der Text), sonst die
     * JSON-Datei der Sprache.
     *
     * @return array<string, string>
     */
    public static function forFrontend(string $locale): array
    {
        $file = lang_path($locale.'.json');

        if ($locale === self::LOCALES[0] || ! is_file($file)) {
            return [];
        }

        /** @var array<string, string> $messages */
        $messages = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return $messages;
    }

    /**
     * Warnungen zu den Rechten eines Kontos (V-10) in der Sprache der Anfrage. Die Dialekte liefern sie auf Deutsch,
     * und so stehen sie auch in der Spalte `account_warnings` — gleich, in welcher Sprache getestet wurde. Übersetzt
     * wird erst bei der Ausgabe.
     *
     * @param  list<array<string, string>>  $warnings
     * @return list<array<string, string>>
     */
    public static function accountWarnings(array $warnings): array
    {
        return array_map(fn (array $warning): array => [
            ...$warning,
            'title' => self::line($warning['title'] ?? ''),
            'recommendation' => self::line($warning['recommendation'] ?? ''),
        ], $warnings);
    }

    /** Ein gespeicherter deutscher Text in der Sprache der Anfrage. */
    private static function line(string $german): string
    {
        $line = __($german);

        return is_string($line) ? $line : $german;
    }
}
