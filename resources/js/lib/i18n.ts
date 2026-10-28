import { usePage } from '@inertiajs/vue3';

/**
 * Deutsch und Englisch (N-06). Der deutsche Text ist der Schlüssel: `t('Verbindungen')`. Auf Englisch kommt die
 * Übersetzung aus `lang/en.json` (einmal je Sprache vom Server); ein Test prüft, dass jeder Schlüssel dort steht.
 */
type Replacements = Record<string, string | number>;

export type Locale = 'de' | 'en';

function messages(): Record<string, string> {
    return (
        (usePage().props.translations as Record<string, string> | undefined) ??
        {}
    );
}

function replace(line: string, replacements: Replacements): string {
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce(
            (text, key) =>
                text.replaceAll(`:${key}`, String(replacements[key])),
            line,
        );
}

/**
 * Laravels Format für Mengen: «:count Verbindung|:count Verbindungen» oder mit Bereichen
 * «{0} Keine Verbindung|{1} Eine Verbindung|[2,*] :count Verbindungen».
 */
function choose(line: string, count: number): string {
    const parts = line.split('|');

    for (const part of parts) {
        const exact = part.match(/^\{(\d+)\}\s?(.*)$/s);

        if (exact && Number(exact[1]) === count) {
            return exact[2];
        }

        const range = part.match(/^\[(\d+|\*),\s*(\d+|\*)\]\s?(.*)$/s);

        if (
            range &&
            (range[1] === '*' || count >= Number(range[1])) &&
            (range[2] === '*' || count <= Number(range[2]))
        ) {
            return range[3];
        }
    }

    const plain = parts.filter(
        (part) => !/^(\{\d+\}|\[[\d*]+,\s*[\d*]+\])/.test(part),
    );

    return plain.length > 1 && count !== 1 ? plain[1] : (plain[0] ?? line);
}

export function t(key: string, replacements: Replacements = {}): string {
    return replace(messages()[key] ?? key, replacements);
}

export function tc(
    key: string,
    count: number,
    replacements: Replacements = {},
): string {
    return replace(choose(messages()[key] ?? key, count), {
        count: formatNumber(count),
        ...replacements,
    });
}

export function locale(): Locale {
    return usePage().props.locale === 'en' ? 'en' : 'de';
}

/** Zahlen und Daten im Schweizer Format der gewählten Sprache: «31’540». */
export function intlLocale(): string {
    return locale() === 'en' ? 'en-CH' : 'de-CH';
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat(intlLocale()).format(value);
}
