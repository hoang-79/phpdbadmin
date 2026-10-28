import type { PasskeyError } from '@laravel/passkeys';
import { t } from '@/lib/i18n';

/**
 * Meldungen der Passkey-Bibliothek im Browser in der Sprache der Oberfläche. Meldungen des Servers kommen schon
 * übersetzt (Deutsch aus lang/de.json, Englisch im Original) und bleiben, wie sie sind. Abbrechen durch die Person
 * ist kein Fehler.
 */
export function passkeyMessage(error: PasskeyError | null): string | null {
    if (!error) {
        return null;
    }

    switch (error.name) {
        case 'UserCancelledError':
            return null;
        case 'NotSupportedError':
            return t(
                'Dieser Browser unterstützt keine Passkeys. Melden Sie sich mit einem Code per Mail an.',
            );
        case 'PasskeyExistsError':
            return t('Dieser Passkey ist schon in Ihrem Konto.');
        case 'InvalidDomainError':
            return t(
                'Passkeys funktionieren nur unter der Adresse dieser Installation.',
            );
        default:
            // 423 der Middleware password.confirm: die Bestätigung ist abgelaufen.
            return error.message.includes('confirmation required')
                ? t(
                      'Bitte bestätigen Sie zuerst kurz Ihre Anmeldung — per Passkey oder Code.',
                  )
                : error.message;
    }
}

/** Vorschlag für den Namen eines neuen Passkeys, z. B. «Safari auf Mac». */
export function suggestedPasskeyName(userAgent: string): string {
    const browser = [
        { pattern: /Edg|Edge/, name: 'Edge' },
        { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
        { pattern: /Firefox|FxiOS/, name: 'Firefox' },
        { pattern: /Chrome|CriOS/, name: 'Chrome' },
        { pattern: /Safari/, name: 'Safari' },
    ].find(({ pattern }) => pattern.test(userAgent))?.name;

    const device = [
        { pattern: /iPhone/, name: 'iPhone' },
        { pattern: /iPad/, name: 'iPad' },
        { pattern: /Android/, name: 'Android' },
        { pattern: /Mac/, name: 'Mac' },
        { pattern: /Windows/, name: 'Windows' },
        { pattern: /Linux/, name: 'Linux' },
    ].find(({ pattern }) => pattern.test(userAgent))?.name;

    return browser && device
        ? t(':browser auf :device', { browser, device })
        : (browser ?? device ?? '');
}
