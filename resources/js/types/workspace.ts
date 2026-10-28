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
    };
    databases: string[] | null;
    database: string | null;
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
