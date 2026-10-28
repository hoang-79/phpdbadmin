import { intlLocale, t } from '@/lib/i18n';

function dateTime(): Intl.DateTimeFormat {
    return new Intl.DateTimeFormat(intlLocale(), {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function date(): Intl.DateTimeFormat {
    return new Intl.DateTimeFormat(intlLocale(), {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function time(): Intl.DateTimeFormat {
    return new Intl.DateTimeFormat(intlLocale(), {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function startOfDay(value: Date): number {
    return new Date(
        value.getFullYear(),
        value.getMonth(),
        value.getDate(),
    ).getTime();
}

/** «28.10.2026» */
export function formatDate(iso: string | null): string {
    return iso ? date().format(new Date(iso)) : '';
}

/** «10:41» */
export function formatTime(iso: string | null): string {
    return iso ? time().format(new Date(iso)) : '';
}

/** «28.10.2026, 10:31» */
export function formatDateTime(iso: string | null): string {
    return iso ? dateTime().format(new Date(iso)) : '';
}

/** Wie in den Wireframes: «heute 09:12», «gestern 17:40», sonst das Datum. */
export function formatRecent(
    iso: string | null,
    now: Date = new Date(),
): string {
    if (!iso) {
        return '';
    }

    const value = new Date(iso);
    const days = Math.round((startOfDay(now) - startOfDay(value)) / 86_400_000);

    if (days === 0) {
        return t('heute :time', { time: time().format(value) });
    }

    if (days === 1) {
        return t('gestern :time', { time: time().format(value) });
    }

    return date().format(value);
}

/** «0:42» für Wartezeiten. */
export function formatCountdown(seconds: number): string {
    const safe = Math.max(0, Math.round(seconds));

    return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
}

/** «24.5 MB» — Grösse einer Tabelle aus der Statistik des Servers. */
export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: unit === 0 ? 0 : 1 }).format(value)} ${units[unit]}`;
}
