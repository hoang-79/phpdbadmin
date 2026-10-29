export type TransferKind = 'export' | 'import';
export type TransferFormat = 'csv' | 'json' | 'sql';
export type TransferStatus =
    | 'draft'
    | 'queued'
    | 'running'
    | 'done'
    | 'failed'
    | 'cancelled';

/** Text in der Sprache der Anfrage, dazu die Meldung des Servers zum Aufklappen. */
export type MessageView = {
    text: string;
    detail: string | null;
};

/** Ein Auftrag, wie TransferSummary::of ihn liefert (Wireframe 11, «Im Hintergrund»). */
export type TransferSummary = {
    id: string;
    kind: TransferKind;
    kindLabel: string;
    format: TransferFormat;
    status: TransferStatus;
    statusLabel: string;
    active: boolean;
    dryRun: boolean;
    inBackground: boolean;
    target: string | null;
    fileName: string | null;
    fileSize: number | null;
    progress: number;
    rows: number;
    totalRows: number | null;
    createdAt: string | null;
    finishedAt: string | null;
    expiresAt: string;
    error: MessageView | null;
    downloadUrl: string | null;
    showUrl: string | null;
    cancelUrl: string | null;
    waitingForWorker: boolean;
};

/** Läuft ein Auftrag im Hintergrund oder in der Anfrage — und bis zu welcher Grenze (B-07)? */
export type TransferMode = {
    background: boolean;
    hasWorker: boolean;
    passwordOnlyInSession: boolean;
    maxRows: number;
    maxMegabytes: number;
};

export type ImportColumn = {
    name: string;
    type: string;
    kind: string;
    required: boolean;
    nullable: boolean;
    generated: boolean;
};

export type FileColumn = {
    index: number;
    name: string;
    samples: (string | null)[];
};

export type RowError = MessageView & { line: number };

export type CsvResult = {
    records: number;
    ready: number;
    imported: number;
    error_count: number;
    errors: RowError[];
    checked_only: boolean;
    analyzed: boolean;
};

export type SqlImportResult = {
    statements: number;
    executed: number;
    skipped: number;
    affected: number;
    kinds: Record<string, number>;
    dangerous: { line: number; keyword: string }[];
    dangerous_count: number;
    transactional: boolean;
    analyzed: boolean;
};

export type DelimiterName = 'comma' | 'semicolon' | 'tab' | 'pipe';

export type ImportTransfer = TransferSummary & {
    schema: string;
    table: string | null;
    options: {
        delimiter: DelimiterName;
        encoding: 'utf-8' | 'windows-1252' | null;
        bom: boolean;
        header: boolean;
        columns: FileColumn[];
        mapping: (string | null)[];
        records: number | null;
        onError: 'skip' | 'abort';
        analyze: boolean;
    };
    result: (CsvResult | SqlImportResult) | null;
    imported: boolean;
    statusUrl: string;
};
