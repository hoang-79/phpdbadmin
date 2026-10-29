/** Struktur ändern (S-02 bis S-05): was die Seiten vom Server bekommen und an ihn schicken. */
export type ColumnTypeOption = {
    key: string;
    label: string;
    params: 'length' | 'precision' | 'values' | null;
    autoIncrement: boolean;
    textual: boolean;
};

export type DefaultKind = 'none' | 'null' | 'value' | 'expression';

/** Eine Spalte im Entwurf; `original` ist der bisherige Name (null = neu). */
export type ColumnDraft = {
    key: string;
    original: string | null;
    drop: boolean;
    primary: boolean;
    name: string;
    type: string;
    custom: string | null;
    length: number | null;
    precision: number | null;
    scale: number | null;
    values: string[];
    nullable: boolean;
    default: { kind: DefaultKind; value: string | null };
    autoIncrement: boolean;
    comment: string | null;
};

export type IndexDraft = {
    key: string;
    original: string | null;
    drop: boolean;
    primary: boolean;
    name: string | null;
    columns: string[];
    unique: boolean;
};

export type ForeignKeyDraft = {
    key: string;
    original: string[] | null;
    drop: boolean;
    name: string | null;
    columns: string[];
    table: string;
    references: string[];
    onDelete: string;
    onUpdate: string;
};

export type TableDraftState = {
    name: string;
    columns: ColumnDraft[];
    indexes: IndexDraft[];
    foreignKeys: ForeignKeyDraft[];
    comment: string | null;
};

/** Spalten und Indizes, wie sie die Seite «Tabelle ändern» vom Server bekommt. */
export type TableDraftProps = {
    columns: Omit<ColumnDraft, 'key' | 'drop' | 'primary'>[];
    indexes: Omit<IndexDraft, 'key' | 'drop'>[];
    foreignKeys: Omit<ForeignKeyDraft, 'key' | 'drop'>[];
    comment: string | null;
};

export type SchemaEditorSettings = {
    types: ColumnTypeOption[];
    rules: string[];
    tables: string[];
    mode: { key: ApplyMode; title: string; explanation: string };
    comments: boolean;
    positions: boolean;
    confirmName: string | null;
};

export type ApplyMode = 'statements' | 'transaction' | 'rebuild';

/** Vorschau einer Strukturänderung (SchemaPlan::preview). */
export type SchemaPlanPreview = {
    target: string;
    mode: ApplyMode;
    title: string;
    explanation: string;
    before: string[];
    after: string[];
    statements: { sql: string; label: string | null }[];
    notes: string[];
    losses: string[];
    confirmName: string | null;
    file: string | null;
};

/** Gescheiterte Strukturänderung: was gelaufen ist (MySQL, MariaDB) bzw. dass nichts blieb. */
export type SchemaFailure = {
    message: string;
    applied: number;
    failed: number | null;
    rolledBack: boolean;
    statements: string[];
};

/** Was an den Server geht — nur JSON-Werte. */
export type JsonValue =
    | string
    | number
    | boolean
    | null
    | JsonValue[]
    | { [key: string]: JsonValue };

export type SchemaPayload = { [key: string]: JsonValue };
