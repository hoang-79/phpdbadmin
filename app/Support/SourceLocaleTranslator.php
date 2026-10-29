<?php

namespace App\Support;

use Illuminate\Translation\Translator;

/**
 * Übersetzer mit Deutsch als Quellsprache (N-06): Der deutsche Text ist der Schlüssel, `lang/de.json` gibt es nicht.
 * Laravels `trans_choice()` wählt die Mehrzahlregel nach der Sprache, in der es den Schlüssel findet — für Deutsch
 * also nie, und es fiele auf Englisch zurück («1 other session ended.» für ein deutsches Konto). In der Quellsprache
 * ist der Schlüssel selbst die Übersetzung.
 */
class SourceLocaleTranslator extends Translator
{
    public const string SOURCE_LOCALE = 'de';

    protected function localeForChoice($key, $locale)
    {
        $locale = $locale ?: $this->locale;

        return $locale === self::SOURCE_LOCALE ? $locale : parent::localeForChoice($key, $locale);
    }
}
