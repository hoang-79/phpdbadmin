import { formatNumber } from '@/lib/i18n';
import type { ConnectionEnvironment } from '@/types';

/** Präfix im Seitentitel (V-04, G-03): Produktion und Test sind auch im Browser-Reiter zu erkennen. */
export function titlePrefix(environment: ConnectionEnvironment): string {
    return { production: 'PROD · ', test: 'TEST · ', local: '' }[environment];
}

/** «≈ 31’540» — Zeilenzahlen aus der Statistik des Servers sind Schätzungen. */
export function formatRows(rows: number | null): string {
    return rows === null ? '' : `≈ ${formatNumber(rows)}`;
}
