import type {
    AccountWarning,
    ConnectionEnvironment,
    DatabaseDriver,
} from './connections';

export type NavigatorItem = {
    name: string;
    kind: string;
    rows: number | null;
    detail: string | null;
};

export type NavigatorGroup = {
    key: string;
    label: string;
    items: NavigatorItem[];
    note: string | null;
};

export type Workspace = {
    connection: {
        id: string;
        name: string;
        driver: DatabaseDriver;
        driverLabel: string;
        serverVersion: string | null;
        environment: ConnectionEnvironment;
        readOnly: boolean;
        accountWarnings: AccountWarning[];
        canManage: boolean;
        canWrite: boolean;
        unlockedUntil: string | null;
    };
    databases: string[] | null;
    database: string | null;
    /** Verbindung auf eine Datenbank festgelegt — Datenbanken (MySQL, MariaDB) lassen sich dann nicht löschen. */
    databaseFixed: boolean;
    databaseLabel: string | null;
    schemaKind: 'schema' | 'database';
    schemas: string[];
    schema: string | null;
    groups: NavigatorGroup[];
};

export type ConnectionProblemView = {
    kind: string;
    title: string;
    explanation: string;
    detail: string | null;
    code: string | null;
    serverMessage: string | null;
};

export type ColumnKind =
    | 'number'
    | 'text'
    | 'choice'
    | 'boolean'
    | 'datetime'
    | 'json'
    | 'binary'
    | 'identifier'
    | 'geometry'
    | 'list'
    | 'other';

export type StructureColumn = {
    position: number;
    name: string;
    type: string;
    kind: ColumnKind;
    kindLabel: string;
    nullable: boolean;
    default: string | null;
    autoIncrement: boolean;
    collation: string | null;
    comment: string | null;
    generated: string | null;
    primary: boolean;
    references: { table: string; column: string | null } | null;
};

export type StructureIndex = {
    name: string;
    columns: string[];
    type: string | null;
    unique: boolean;
    primary: boolean;
};

export type StructureForeignKey = {
    name: string;
    columns: string[];
    foreignSchema: string | null;
    foreignTable: string;
    foreignColumns: string[];
    onUpdate: string;
    onDelete: string;
};

export type StructureReference = {
    name: string;
    table: string;
    columns: string[];
    foreignColumns: string[];
    onDelete: string;
};

export type StructureTrigger = {
    name: string;
    timing: string;
    event: string;
    definition: string;
};

export type ObjectStructure = {
    object: {
        name: string;
        kind: 'table' | 'view';
        rows: number | null;
        size: number | null;
        comment: string | null;
        engine: string | null;
        collation: string | null;
    };
    columns: StructureColumn[];
    indexes: StructureIndex[];
    foreignKeys: StructureForeignKey[];
    referencedBy: StructureReference[];
    checks: { name: string; definition: string }[];
    triggers: StructureTrigger[];
    ddl: string;
};

/** Ein Wert im Raster, gleich in allen vier Datenbanken (Cells.php). */
export type Cell =
    | string
    | number
    | boolean
    | null
    | { binary: number; hex: string }
    | { text: string; length: number };

export type GridColumn = {
    name: string;
    type: string;
    kind: ColumnKind;
    kindLabel: string;
    nullable: boolean;
    primary: boolean;
    references: { table: string; column: string } | null;
};

export type GridPage = {
    primaryKey: string[];
    rows: Cell[][];
    keys: (string | null)[][];
    hasMore: boolean;
    next: { after?: (string | null)[]; offset?: number } | null;
    labels: Record<string, Record<string, string>>;
    total: { count: number | null; exact: boolean };
    durationMs: number;
};

export type GridFilter = { c: string; o: string; v?: string; b?: 'or' };

/** Ausschnitt des Rasters, wie er in der Adresse steht (GridQuery.php). */
export type GridQueryState = {
    sort?: string;
    f?: GridFilter[];
    where?: string;
    q?: string;
};

export type FilterOperatorOption = {
    value: string;
    symbol: string;
    needsValue: boolean;
};

export type GridError = {
    reason: string;
    message: string;
    serverMessage: string | null;
};

export type GridView = { hidden: string[]; widths: Record<string, number> };

export type RowDetailData = {
    values: Cell[];
    outgoing: {
        column: string;
        table: string;
        foreignColumn: string;
        value: string;
        label: string | null;
    }[];
    incoming: {
        name: string;
        table: string;
        columns: string[];
        values: (string | null)[];
        count: number | null;
    }[];
};

/**
 * Herkunft eines Ergebnisses (Q-05): eine Tabelle mit vollständigem Primärschlüssel — dann bearbeitbar über die
 * Endpunkte des Rasters. `columns` je Spalte des Ergebnisses die Spalte der Tabelle oder null (nur lesen).
 */
export type ResultSource = {
    editable: boolean;
    reason: string | null;
    schema: string | null;
    table: string | null;
    keyColumns: number[];
    columns: (GridColumn | null)[];
};

/** Suche über ein ganzes Schema (D-08): Treffer je Tabelle und was ungesucht blieb. */
export type SchemaSearchOutcome = {
    tables: { table: string; hits: number; more: boolean; columns: string[] }[];
    searched: number;
    total: number;
    unsearched: string[];
    failed: { table: string; message: string }[];
    stoppedBy: 'time' | 'hits' | null;
    hits: number;
    durationMs: number;
};

export type SqlResult = {
    sql: string;
    status: 'ok' | 'error' | 'cancelled' | 'timeout' | 'skipped';
    columns: { name: string; type: string | null }[];
    rows: Cell[][];
    truncated: boolean;
    affected: number | null;
    durationMs: number;
    message: string | null;
    source?: ResultSource | null;
};

export type SqlHistoryEntry = {
    id: number;
    sql: string;
    status: string;
    durationMs: number | null;
    rows: number | null;
    at: string | null;
};

export type SavedSqlQuery = {
    id: number;
    name: string;
    sql: string;
    updatedAt: string | null;
};

export type SqlEditorSettings = {
    timeoutSeconds: number;
    rowLimit: number;
    maxRowLimit: number;
    canWrite: boolean;
};
