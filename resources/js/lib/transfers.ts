import { maintenance } from '@/routes/workspace/server';
import { backup, index } from '@/routes/workspace/transfers';
import type { Workspace } from '@/types';

/**
 * Adresse der Seite «Export und Import» (Wireframe 11). Mit Schema und Tabelle ist der Export vorbelegt — so
 * verlinkt der Knopf «Exportieren» im Objektkopf hierher. Bei PostgreSQL mit «alle Datenbanken» trägt die Adresse
 * die gewählte Datenbank mit (`?db=`).
 */
export function transfersHref(
    workspace: Pick<Workspace, 'connection' | 'database'>,
    preset: {
        schema?: string | null;
        table?: string | null;
        scope?: 'schema';
    } = {},
    section: 'exportieren' | 'importieren' | null = null,
): string {
    const query: Record<string, string> = {};

    if (preset.scope) {
        query.scope = preset.scope;
    }

    if (preset.schema) {
        query.schema = preset.schema;
    }

    if (preset.table) {
        query.table = preset.table;
    }

    if (workspace.database) {
        query.db = workspace.database;
    }

    return (
        index.url(workspace.connection.id, { query }) +
        (section ? `#${section}` : '')
    );
}

/** Sichern und Wiederherstellen mit den Werkzeugen des Servers (E-03) — mit der Datenbank vorgewählt. */
export function backupHref(
    workspace: Pick<Workspace, 'connection' | 'database'>,
    schema: string | null,
): string {
    const query: Record<string, string> = {};

    if (schema) {
        query.schema = schema;
    }

    if (workspace.database) {
        query.db = workspace.database;
    }

    return backup.url(workspace.connection.id, { query });
}

/** Wartung (W-05, W-06) mit Art, Datenbank und Tabelle vorgewählt (O-10) — die Seite zeigt Vorschau und Bestätigung. */
export function maintenanceHref(
    workspace: Pick<Workspace, 'connection' | 'database'>,
    preset: { operation: string; schema: string | null; table?: string | null },
): string {
    const query: Record<string, string> = { operation: preset.operation };

    if (preset.schema) {
        query.schema = preset.schema;
    }

    if (preset.table) {
        query.table = preset.table;
    }

    if (workspace.database) {
        query.db = workspace.database;
    }

    return maintenance.url(workspace.connection.id, { query });
}

/** `?db=` für Formulare, die an eine Adresse der Verbindung gehen. */
export function databaseQuery(workspace: Pick<Workspace, 'database'>): {
    query?: { db: string };
} {
    return workspace.database ? { query: { db: workspace.database } } : {};
}
