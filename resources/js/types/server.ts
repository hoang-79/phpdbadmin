/** Server-Verwaltung (W-01 bis W-05): was die Seiten vom Server bekommen. */
export type ServerSection =
    | 'overview'
    | 'processes'
    | 'variables'
    | 'accounts'
    | 'maintenance';

export type ServerMeta = {
    section: ServerSection;
    hasServer: boolean;
    canChange: boolean;
    blocked: 'read_only' | 'not_manager' | null;
    confirmName: string | null;
};

export type ServerFact = {
    key: string;
    value: string | number;
    kind: 'text' | 'bytes' | 'number' | 'datetime';
};

export type DatabaseSize = {
    name: string;
    size: number | null;
    charset: string | null;
    collation: string | null;
};

export type ServerOverview = {
    version: string;
    uptime: number | null;
    charset: string | null;
    collation: string | null;
    facts: ServerFact[];
    databases: DatabaseSize[];
    schemas: { name: string; size: number | null }[] | null;
};

export type ServerProcess = {
    id: number;
    user: string;
    host: string | null;
    program: string | null;
    database: string | null;
    command: string | null;
    state: string | null;
    seconds: number | null;
    statement: string | null;
    own: boolean;
    endStatements: { query: string; session: string };
};

export type ServerSetting = {
    name: string;
    value: string;
    description: string | null;
};

export type ServerAccount = {
    name: string;
    host: string | null;
    attributes: string[];
    grants: string[];
    own: boolean;
};

export type ChangePreview = {
    target: string;
    statements: string[];
    notes: string[];
};

export type MaintenanceResult = {
    table: string | null;
    status: 'ok' | 'note' | 'warning' | 'error';
    message: string;
};
