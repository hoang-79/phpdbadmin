import { intlLocale, t, tc } from '@/lib/i18n';

/** «18,4 MB» — Grössen von Datenbanken und Dateien, Basis 1024 wie in den Werkzeugen der Server. */
export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
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

/** «3 Tage 4 Std.», «12 Min.», «42 s» — Laufzeit des Servers und Dauer eines Prozesses. */
export function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    const days = Math.floor(seconds / 86_400);
    const hours = Math.floor((seconds % 86_400) / 3_600);
    const minutes = Math.floor((seconds % 3_600) / 60);

    if (days > 0) {
        return `${tc(':count Tag|:count Tage', days)} ${t(':count Std.', { count: hours })}`;
    }

    if (hours > 0) {
        return `${t(':count Std.', { count: hours })} ${t(':count Min.', { count: minutes })}`;
    }

    if (minutes > 0) {
        return t(':count Min.', { count: minutes });
    }

    return t(':count s', { count: Math.max(0, seconds) });
}

/** Adresse einer Server-Seite mit der geöffneten Datenbank (PostgreSQL mit «alle Datenbanken»). */
export function databaseQuery(database: string | null): {
    query?: { db: string };
} {
    return database ? { query: { db: database } } : {};
}
