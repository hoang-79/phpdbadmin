import type { QueryParams } from '@/wayfinder';
import { schema as schemaRoute } from '@/routes/workspace';
import {
    count as countRoute,
    row as rowRoute,
    rows as rowsRoute,
    structure as structureRoute,
    view as viewRoute,
} from '@/routes/workspace/object';
import {
    definition as definitionRoute,
    diagram as diagramRoute,
    object as objectRoute,
} from '@/routes/workspace';
import { create as definitionCreateRoute } from '@/routes/workspace/definition';
import { t } from '@/lib/i18n';
import type { GridQueryState, ObjectKindKey, Workspace } from '@/types';

/**
 * Adressen im Arbeitsbildschirm. Wayfinder setzt Parameter unverändert ein; Namen von Schemas und Tabellen dürfen
 * aber Leerzeichen, «#» oder «?» enthalten — deshalb hier kodiert. Bei PostgreSQL mit «alle Datenbanken» trägt jede
 * Adresse die gewählte Datenbank mit (`?db=`).
 */
function dbQuery(workspace: Workspace): QueryParams {
    return workspace.database ? { db: workspace.database } : {};
}

function objectArgs(
    workspace: Workspace,
    object: string,
    schema: string | null = workspace.schema,
): [string, string, string] {
    return [
        workspace.connection.id,
        encodeURIComponent(schema ?? ''),
        encodeURIComponent(object),
    ];
}

/** Ausschnitt des Rasters als Abfrageparameter; Listen als Index-Objekte, damit PHP `f[0][c]` liest. */
export function gridParams(state: GridQueryState): QueryParams {
    const params: QueryParams = {};

    if (state.sort) {
        params.sort = state.sort;
    }

    if (state.f?.length) {
        params.f = Object.fromEntries(
            state.f.map((filter, index) => [
                String(index),
                Object.fromEntries(
                    Object.entries(filter).filter(
                        ([, value]) => value !== undefined,
                    ),
                ) as QueryParams,
            ]),
        );
    }

    if (state.where) {
        params.where = state.where;
    }

    if (state.q) {
        params.q = state.q;
    }

    return params;
}

export function schemaHref(workspace: Workspace, schema: string): string {
    return schemaRoute.url(
        [workspace.connection.id, encodeURIComponent(schema)],
        { query: dbQuery(workspace) },
    );
}

export function dataHref(
    workspace: Workspace,
    object: string,
    state: GridQueryState = {},
    schema: string | null = workspace.schema,
): string {
    return objectRoute.url(objectArgs(workspace, object, schema), {
        query: { ...dbQuery(workspace), ...gridParams(state) },
    });
}

export function structureHref(
    workspace: Workspace,
    object: string,
    schema: string | null = workspace.schema,
): string {
    return structureRoute.url(objectArgs(workspace, object, schema), {
        query: dbQuery(workspace),
    });
}

export function rowsUrl(
    workspace: Workspace,
    object: string,
    state: GridQueryState,
    page: { after?: (string | null)[]; offset?: number },
): string {
    return rowsRoute.url(objectArgs(workspace, object), {
        query: {
            ...dbQuery(workspace),
            ...gridParams(state),
            ...(page.after
                ? { after: page.after.map((value) => value ?? '') }
                : {}),
            ...(page.offset ? { offset: page.offset } : {}),
        },
    });
}

export function countUrl(
    workspace: Workspace,
    object: string,
    state: GridQueryState,
): string {
    return countRoute.url(objectArgs(workspace, object), {
        query: { ...dbQuery(workspace), ...gridParams(state) },
    });
}

export function rowUrl(
    workspace: Workspace,
    object: string,
    key: (string | null)[],
): string {
    return rowRoute.url(objectArgs(workspace, object), {
        query: { ...dbQuery(workspace), key: key.map((value) => value ?? '') },
    });
}

export function viewUrl(workspace: Workspace, object: string): string {
    return viewRoute.url(objectArgs(workspace, object), {
        query: dbQuery(workspace),
    });
}

const definitionKinds: ObjectKindKey[] = [
    'view',
    'function',
    'procedure',
    'trigger',
    'event',
    'sequence',
    'type',
];

/** Ist das eine Art mit eigener Detailseite (S-06)? */
export function isDefinitionKind(kind: string): kind is ObjectKindKey {
    return (definitionKinds as string[]).includes(kind);
}

/**
 * Detailseite eines Objekts (S-06). Der Zusatz unterscheidet bei PostgreSQL Trigger gleichen Namens (Tabelle) und
 * überladene Routinen (Parameter).
 */
export function definitionHref(
    workspace: Workspace,
    kind: ObjectKindKey,
    name: string,
    qualifier: string | null = null,
    schema: string | null = workspace.schema,
): string {
    return definitionRoute.url(
        [
            workspace.connection.id,
            encodeURIComponent(schema ?? ''),
            kind,
            encodeURIComponent(name),
        ],
        {
            query: {
                ...dbQuery(workspace),
                ...(qualifier !== null ? { qualifier } : {}),
            },
        },
    );
}

/** Seite zum Anlegen eines Objekts dieser Art im gewählten Schema. */
export function definitionCreateHref(
    workspace: Workspace,
    kind: ObjectKindKey,
): string {
    return definitionCreateRoute.url(
        [
            workspace.connection.id,
            encodeURIComponent(workspace.schema ?? ''),
            kind,
        ],
        { query: dbQuery(workspace) },
    );
}

/**
 * Ziel eines Eintrags im Navigator: Tabellen und Sichten öffnen die Daten, Routinen, Trigger, Ereignisse, Sequenzen
 * und Typen ihre Detailseite. Trigger tragen ihre Tabelle mit (PostgreSQL: gleicher Name an mehreren Tabellen).
 */
export function objectHref(
    workspace: Workspace,
    item: { name: string; kind: string; detail?: string | null },
): string | null {
    if (item.kind === 'table' || item.kind === 'view') {
        return dataHref(workspace, item.name);
    }

    if (!isDefinitionKind(item.kind)) {
        return null;
    }

    return definitionHref(
        workspace,
        item.kind,
        item.name,
        item.kind === 'trigger' && workspace.connection.driver === 'pgsql'
            ? (item.detail ?? null)
            : null,
    );
}

/** Adresse mit der gewählten Datenbank (PostgreSQL mit «alle Datenbanken») — für JSON-Anfragen. */
export function withDb(workspace: Workspace): { query: QueryParams } {
    return { query: dbQuery(workspace) };
}

/** Beziehungsdiagramm eines Schemas (S-07), auf Wunsch nur mit diesen Tabellen. */
export function diagramHref(
    workspace: Workspace,
    tables: string[] = [],
    schema: string | null = workspace.schema,
): string {
    return diagramRoute.url(
        [workspace.connection.id, encodeURIComponent(schema ?? '')],
        {
            query: {
                ...dbQuery(workspace),
                ...(tables.length ? { t: tables } : {}),
            },
        },
    );
}

/**
 * Warum Strukturänderungen gerade nicht gehen (S-02 bis S-05) — oder null: Recht «schreiben» und kein Schreibschutz,
 * ausser er ist auf Zeit aufgehoben (V-11).
 */
export function structureBlock(
    connection: Workspace['connection'],
): string | null {
    if (!connection.canWrite) {
        return t('Dafür braucht es das Recht «schreiben».');
    }

    if (connection.readOnly && !connection.unlockedUntil) {
        return t('Schreibgeschützt — Strukturänderungen sind gesperrt.');
    }

    return null;
}
