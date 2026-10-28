import { schema as schemaRoute } from '@/routes/workspace';
import { structure as structureRoute } from '@/routes/workspace/object';
import type { Workspace } from '@/types';

/**
 * Adressen im Arbeitsbildschirm. Wayfinder setzt Parameter unverändert ein; Namen von Schemas und Tabellen dürfen
 * aber Leerzeichen, «#» oder «?» enthalten — deshalb hier kodiert. Bei PostgreSQL mit «alle Datenbanken» trägt jede
 * Adresse die gewählte Datenbank mit (`?db=`).
 */
function query(workspace: Workspace): { query?: { db: string } } {
    return workspace.database ? { query: { db: workspace.database } } : {};
}

export function schemaHref(workspace: Workspace, schema: string): string {
    return schemaRoute.url(
        [workspace.connection.id, encodeURIComponent(schema)],
        query(workspace),
    );
}

export function structureHref(
    workspace: Workspace,
    object: string,
    schema: string | null = workspace.schema,
): string {
    return structureRoute.url(
        [
            workspace.connection.id,
            encodeURIComponent(schema ?? ''),
            encodeURIComponent(object),
        ],
        query(workspace),
    );
}

/** Ziel eines Eintrags im Navigator: Tabellen und Sichten öffnen, übrige Objekte noch nicht. */
export function objectHref(
    workspace: Workspace,
    item: { name: string; kind: string },
): string | null {
    return item.kind === 'table' || item.kind === 'view'
        ? structureHref(workspace, item.name)
        : null;
}
