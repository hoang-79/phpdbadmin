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
