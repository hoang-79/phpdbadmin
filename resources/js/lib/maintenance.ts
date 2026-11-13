import { t } from '@/lib/i18n';
import type { DatabaseDriver } from '@/types';

/**
 * Wartung (W-05, W-06) in Worten — für die Wartungsseite und die Menüs «Datenbank» und «Weitere Aktionen» (O-10).
 * `sql` nennt die Anweisung, unter der man sie aus phpMyAdmin oder der Doku des Servers kennt.
 */
export function maintenanceDescription(
    operation: string,
    driver: DatabaseDriver,
): { title: string; sql: string; text: string } {
    switch (operation) {
        case 'analyze':
            return {
                title: t('Statistik aktualisieren'),
                sql: 'ANALYZE',
                text: t(
                    'Der Server plant Abfragen nach seiner Statistik — nach grossen Änderungen lohnt es sich.',
                ),
            };
        case 'vacuum':
            return {
                title: t('Platz freigeben'),
                sql: 'VACUUM',
                text:
                    driver === 'sqlite'
                        ? t(
                              'Baut die Datei neu auf und gibt freie Seiten ans System zurück.',
                          )
                        : t(
                              'Gibt den Platz gelöschter Zeilen zur Wiederverwendung frei.',
                          ),
            };
        case 'optimize':
            return {
                title: t('Tabellen neu aufbauen'),
                sql: 'OPTIMIZE TABLE',
                text: t('Ordnet Daten und Indizes neu und gibt Platz frei.'),
            };
        case 'check':
            return {
                title: t('Integrität prüfen'),
                sql:
                    driver === 'sqlite'
                        ? 'PRAGMA integrity_check'
                        : 'CHECK TABLE',
                text: t(
                    'Sucht nach beschädigten Seiten und Indizes; ändert nichts.',
                ),
            };
        default:
            return { title: t('Wartung'), sql: '', text: '' };
    }
}
