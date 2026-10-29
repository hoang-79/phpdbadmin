/** Objekte neben den Tabellen (S-06), Beziehungsdiagramm (S-07) und EXPLAIN (Q-06): was die Seiten bekommen. */
export type ObjectKindKey =
    | 'view'
    | 'function'
    | 'procedure'
    | 'trigger'
    | 'event'
    | 'sequence'
    | 'type';

export type ObjectReference = {
    kind: 'table' | 'function';
    schema: string;
    name: string;
    qualifier: string | null;
};

export type ObjectDefinitionView = {
    kind: ObjectKindKey;
    schema: string;
    name: string;
    qualifier: string | null;
    ddl: string;
    editable: string | null;
    facts: { key: string; value: string }[];
    columns: { name: string; type: string }[];
    values: string[];
    references: ObjectReference[];
    overloads: string[];
    variant: string | null;
};

export type ObjectChangeMeta = {
    canChange: boolean;
    blocked: 'read_only' | 'right' | null;
    confirmName: string | null;
    replacesInPlace: boolean;
    transactional: boolean;
};

export type ObjectChangePreview = {
    target: string;
    statements: string[];
    notes: string[];
    transactional: boolean;
};

export type DiagramColumn = {
    name: string;
    type: string;
    primary: boolean;
    nullable: boolean;
    foreign: boolean;
};

export type DiagramTable = {
    name: string;
    columns: DiagramColumn[];
    x: number;
    y: number;
    width: number;
    height: number;
};

export type DiagramRelation = {
    name: string;
    table: string;
    columns: string[];
    foreignTable: string;
    foreignColumns: string[];
};

export type Diagram = {
    tables: DiagramTable[];
    relations: DiagramRelation[];
    width: number;
    height: number;
};

export type DiagramCatalogEntry = {
    name: string;
    relations: number;
};

export type ExplainNode = {
    id: number;
    operation: string;
    object: string | null;
    rows: number | null;
    actualRows: number | null;
    loops: number | null;
    cost: number | null;
    time: number | null;
    details: string[];
    children: ExplainNode[];
};

export type ExplainHint = {
    kind: 'full_scan' | 'filesort' | 'temporary' | 'index_unused' | 'estimate';
    node: number;
    message: string;
};

export type ExplainResult = {
    sql: string;
    analyzed: boolean;
    analyzable: boolean;
    plan: ExplainNode;
    hints: ExplainHint[];
    raw: string;
    durationMs: number;
};
