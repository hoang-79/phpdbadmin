import type { PasskeyError } from '@laravel/passkeys';

/**
 * Meldungen der Passkey-Bibliothek im Browser auf Deutsch. Meldungen des Servers kommen schon übersetzt
 * (lang/de.json) und bleiben, wie sie sind. Abbrechen durch die Person ist kein Fehler.
 */
export function passkeyMessage(error: PasskeyError | null): string | null {
    if (!error) {
        return null;
    }

    switch (error.name) {
        case 'UserCancelledError':
            return null;
        case 'NotSupportedError':
            return 'Dieser Browser unterstützt keine Passkeys. Melden Sie sich mit einem Code per Mail an.';
        case 'PasskeyExistsError':
            return 'Dieser Passkey ist schon in Ihrem Konto.';
        case 'InvalidDomainError':
            return 'Passkeys funktionieren nur unter der Adresse dieser Installation.';
        default:
            return error.message;
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

    return [browser, device].filter(Boolean).join(' auf ');
}
