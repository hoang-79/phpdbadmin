import { sql as sqlRoute } from '@/routes/workspace';
import { alter as alterRoute } from '@/routes/workspace/object';
import actionRoutes from '@/routes/workspace/object/actions';
import alterRoutes from '@/routes/workspace/object/alter';
import schemaRoutes from '@/routes/workspace/schemas';
import tableRoutes from '@/routes/workspace/table';
import type {
    ColumnDraft,
    ColumnTypeOption,
    ForeignKeyDraft,
    IndexDraft,
    SchemaPayload,
    TableDraftProps,
    TableDraftState,
    Workspace,
} from '@/types';

/**
 * Entwurf einer Tabelle im Browser (S-02, S-03): neue Zeilen, der Vergleich mit dem Stand beim Öffnen und was davon an
 * den Server geht. Geprüft und in SQL übersetzt wird nur auf dem Server — hier entsteht kein SQL.
 */
let counter = 0;

function key(prefix: string): string {
    counter += 1;

    return `${prefix}-${counter}`;
}

export function newColumn(name = ''): ColumnDraft {
    return {
        key: key('spalte'),
        original: null,
        drop: false,
        primary: false,
        name,
        type: 'string',
        custom: null,
        length: 255,
        precision: null,
        scale: null,
        values: [],
        nullable: true,
        default: { kind: 'none', value: null },
        autoIncrement: false,
        comment: null,
    };
}

export function newIndex(): IndexDraft {
    return {
        key: key('index'),
        original: null,
        drop: false,
        primary: false,
        name: null,
        columns: [],
        unique: false,
    };
}

export function newForeignKey(): ForeignKeyDraft {
    return {
        key: key('fremdschluessel'),
        original: null,
        drop: false,
        name: null,
        columns: [],
        table: '',
        references: [],
        onDelete: 'no action',
        onUpdate: 'no action',
    };
}

/** Der Entwurf aus dem Stand der Tabelle (Seite «Tabelle ändern»). */
export function draftFromProps(
    name: string,
    props: TableDraftProps,
): TableDraftState {
    return {
        name,
        columns: props.columns.map((column) => ({
            ...column,
            values: [...column.values],
            default: { ...column.default },
            key: key('spalte'),
            drop: false,
            primary: false,
        })),
        indexes: props.indexes.map((index) => ({
            ...index,
            columns: [...index.columns],
            key: key('index'),
            drop: false,
        })),
        foreignKeys: props.foreignKeys.map((foreignKey) => ({
            ...foreignKey,
            columns: [...foreignKey.columns],
            references: [...foreignKey.references],
            original: foreignKey.original ? [...foreignKey.original] : null,
            key: key('fremdschluessel'),
            drop: false,
        })),
        comment: props.comment,
    };
}

/** Eine leere Tabelle mit Primärschlüssel «id» als Vorschlag (Seite «Tabelle anlegen»). */
export function emptyDraft(types: ColumnTypeOption[]): TableDraftState {
    const integer =
        ['unsignedBigInteger', 'bigInteger', 'integer'].find((type) =>
            types.some((option) => option.key === type),
        ) ?? 'integer';

    return {
        name: '',
        columns: [
            {
                ...newColumn('id'),
                type: integer,
                length: null,
                nullable: false,
                autoIncrement: true,
                primary: true,
            },
        ],
        indexes: [],
        foreignKeys: [],
        comment: null,
    };
}

/** Die Felder einer Spalte, die ihre Definition ausmachen (ohne Name). */
function definition(column: Omit<ColumnDraft, 'key'>): string {
    return JSON.stringify([
        column.type,
        column.custom,
        column.length,
        column.precision,
        column.scale,
        column.values,
        column.nullable,
        column.default.kind,
        column.default.kind === 'value' || column.default.kind === 'expression'
            ? column.default.value
            : null,
        column.autoIncrement,
        column.comment ?? null,
    ]);
}

export function isChanged(
    column: ColumnDraft,
    snapshot: Map<string, Omit<ColumnDraft, 'key'>>,
): boolean {
    const before =
        column.original !== null ? snapshot.get(column.original) : undefined;

    return before !== undefined && definition(column) !== definition(before);
}

export function isRenamed(column: ColumnDraft): boolean {
    return column.original !== null && column.original !== column.name;
}

function columnPayload(
    column: ColumnDraft,
    snapshot: Map<string, Omit<ColumnDraft, 'key'>> | null,
): SchemaPayload {
    return {
        ...(snapshot !== null
            ? {
                  original: column.original,
                  drop: column.drop,
                  changed: isChanged(column, snapshot),
              }
            : {}),
        name: column.name.trim(),
        type: column.type,
        custom: column.type === 'custom' ? column.custom : null,
        length: column.length,
        precision: column.precision,
        scale: column.scale,
        values: column.values,
        nullable: column.nullable,
        default: column.default,
        autoIncrement: column.autoIncrement,
        comment: column.comment ? column.comment : null,
    };
}

export function createPayload(draft: TableDraftState): SchemaPayload {
    return {
        name: draft.name.trim(),
        columns: draft.columns.map((column) => columnPayload(column, null)),
        primary: draft.columns
            .filter((column) => column.primary && !column.autoIncrement)
            .map((column) => column.name.trim()),
        indexes: draft.indexes.map((index) => ({
            name: index.name,
            columns: index.columns,
            unique: index.unique,
        })),
        foreignKeys: draft.foreignKeys.map((foreignKey) => ({
            name: foreignKey.name,
            columns: foreignKey.columns,
            table: foreignKey.table,
            references: foreignKey.references,
            onDelete: foreignKey.onDelete,
            onUpdate: foreignKey.onUpdate,
        })),
        comment: draft.comment,
    };
}

/**
 * Der Entwurf zum Ändern: alle Spalten, Indizes und Fremdschlüssel in der Reihenfolge des Formulars — bestehende mit
 * ihrem bisherigen Namen und ob sie wegfallen oder geändert sind. So zeigen Fehler des Servers (columns.3.name) auf
 * die richtige Zeile.
 */
export function alterPayload(
    draft: TableDraftState,
    snapshot: Map<string, Omit<ColumnDraft, 'key'>>,
    originalComment: string | null,
): SchemaPayload {
    return {
        columns: draft.columns.map((column) => columnPayload(column, snapshot)),
        indexes: draft.indexes.map((index) => ({
            original: index.original,
            drop: index.drop,
            name: index.name,
            columns: index.columns,
            unique: index.unique,
        })),
        foreignKeys: draft.foreignKeys.map((foreignKey) => ({
            original: foreignKey.original,
            drop: foreignKey.drop,
            name: foreignKey.name,
            columns: foreignKey.columns,
            table: foreignKey.table,
            references: foreignKey.references,
            onDelete: foreignKey.onDelete,
            onUpdate: foreignKey.onUpdate,
        })),
        comment: draft.comment,
        commentChanged: (draft.comment ?? '') !== (originalComment ?? ''),
    };
}

/** Typ einer Spalte kurz, wie in der Datenbank («varchar(40)», «decimal(10,2)»). */
export function typeLabel(
    column: ColumnDraft,
    types: ColumnTypeOption[],
): string {
    if (column.type === 'custom') {
        return column.custom ?? '';
    }

    const option = types.find((type) => type.key === column.type);
    const label = option?.label ?? column.type;

    if (option?.params === 'length' && column.length) {
        return `${label}(${column.length})`;
    }

    if (option?.params === 'precision' && column.precision) {
        return `${label}(${column.precision},${column.scale ?? 0})`;
    }

    if (option?.params === 'values' && column.values.length) {
        return `${label}(${column.values.map((value) => `'${value}'`).join(',')})`;
    }

    return label;
}

/** Ziel der Anfragen: bei PostgreSQL mit «alle Datenbanken» die gewählte Datenbank im Körper (`database`). */
export function databaseField(workspace: Workspace): { database: string } {
    return { database: workspace.database ?? '' };
}

/** Datenbank in der Adresse (`?db=`), wie überall im Arbeitsbildschirm. */
export function dbQuery(workspace: Workspace): Record<string, string> {
    return workspace.database ? { db: workspace.database } : {};
}

/** Namen in der Adresse kodiert — Schemas und Tabellen dürfen Leerzeichen, «#» oder «?» enthalten. */
function objectArgs(
    workspace: Workspace,
    object: string,
): [string, string, string] {
    return [
        workspace.connection.id,
        encodeURIComponent(workspace.schema ?? ''),
        encodeURIComponent(object),
    ];
}

function schemaArgs(workspace: Workspace, schema: string): [string, string] {
    return [workspace.connection.id, encodeURIComponent(schema)];
}

/** Seite «Tabelle ändern». */
export function alterHref(workspace: Workspace, object: string): string {
    return alterRoute.url(objectArgs(workspace, object), {
        query: dbQuery(workspace),
    });
}

/** Seite «Tabelle anlegen» im Schema. */
export function newTableHref(workspace: Workspace, schema: string): string {
    return tableRoutes.create.url(schemaArgs(workspace, schema), {
        query: dbQuery(workspace),
    });
}

type Urls = { preview: string; apply: string };

export function alterUrls(workspace: Workspace, object: string): Urls {
    const args = objectArgs(workspace, object);

    return {
        preview: alterRoutes.preview.url(args),
        apply: alterRoutes.apply.url(args),
    };
}

export function actionUrls(workspace: Workspace, object: string): Urls {
    const args = objectArgs(workspace, object);

    return {
        preview: actionRoutes.preview.url(args),
        apply: actionRoutes.apply.url(args),
    };
}

export function tableUrls(workspace: Workspace, schema: string): Urls {
    const args = schemaArgs(workspace, schema);

    return {
        preview: tableRoutes.preview.url(args),
        apply: tableRoutes.store.url(args),
    };
}

export function schemaUrls(workspace: Workspace): Urls & { folders: string } {
    const id = workspace.connection.id;

    return {
        preview: schemaRoutes.preview.url(id),
        apply: schemaRoutes.apply.url(id),
        folders: schemaRoutes.folders.url(id),
    };
}

/** Die Anweisungen der Vorschau im SQL-Editor öffnen (als Vorlage in einem neuen Reiter). */
export function editorHref(workspace: Workspace, statements: string[]): string {
    return sqlRoute.url(workspace.connection.id, {
        query: {
            tab: 99,
            ...(workspace.schema ? { schema: workspace.schema } : {}),
            ...dbQuery(workspace),
            vorlage: statements.map((sql) => `${sql};`).join('\n'),
        },
    });
}
