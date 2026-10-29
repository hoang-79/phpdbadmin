import { migration as migrationRoute } from '@/routes/workspace/object';
import type { Workspace } from '@/types';

/**
 * Seite «Laravel-Migration» einer Tabelle (S-09). Namen von Schemas und Tabellen kodiert wie in `lib/workspace.ts`;
 * bei PostgreSQL mit «alle Datenbanken» mit der gewählten Datenbank.
 */
export function migrationHref(workspace: Workspace, object: string): string {
    return migrationRoute.url(
        [
            workspace.connection.id,
            encodeURIComponent(workspace.schema ?? ''),
            encodeURIComponent(object),
        ],
        { query: workspace.database ? { db: workspace.database } : {} },
    );
}
