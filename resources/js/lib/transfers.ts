import { index } from '@/routes/workspace/transfers';
import type { Workspace } from '@/types';

/**
 * Adresse der Seite «Export und Import» (Wireframe 11). Mit Schema und Tabelle ist der Export vorbelegt — so
 * verlinkt der Knopf «Exportieren» im Objektkopf hierher. Bei PostgreSQL mit «alle Datenbanken» trägt die Adresse
 * die gewählte Datenbank mit (`?db=`).
 */
export function transfersHref(
    workspace: Pick<Workspace, 'connection' | 'database'>,
    preset: { schema?: string | null; table?: string | null } = {},
): string {
    const query: Record<string, string> = {};

    if (preset.schema) {
        query.schema = preset.schema;
    }

    if (preset.table) {
        query.table = preset.table;
    }

    if (workspace.database) {
        query.db = workspace.database;
    }

    return index.url(workspace.connection.id, { query });
}

/** `?db=` für Formulare, die an eine Adresse der Verbindung gehen. */
export function databaseQuery(workspace: Pick<Workspace, 'database'>): {
    query?: { db: string };
} {
    return workspace.database ? { query: { db: workspace.database } } : {};
}
