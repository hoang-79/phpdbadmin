import type { QueryParams } from '@/wayfinder';
import { schema as schemaRoute } from '@/routes/workspace';
import {
    count as countRoute,
    row as rowRoute,
    rows as rowsRoute,
    structure as structureRoute,
    view as viewRoute,
} from '@/routes/workspace/object';
import { object as objectRoute } from '@/routes/workspace';
import type { GridQueryState, Workspace } from '@/types';

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

/** Ziel eines Eintrags im Navigator: Tabellen und Sichten öffnen die Daten, übrige Objekte noch nicht. */
export function objectHref(
    workspace: Workspace,
    item: { name: string; kind: string },
): string | null {
    return item.kind === 'table' || item.kind === 'view'
        ? dataHref(workspace, item.name)
        : null;
}
