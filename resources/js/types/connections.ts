export type ConnectionEnvironment = 'local' | 'test' | 'production';
export type DatabaseDriver = 'mariadb' | 'mysql' | 'pgsql' | 'sqlite';

export type AccountWarning = {
    key: string;
    title: string;
    recommendation: string;
};

export type ConnectionSummary = {
    id: string;
    name: string;
    group: string | null;
    driver: DatabaseDriver;
    driverLabel: string;
    serverVersion: string | null;
    location: string | null;
    database: string | null;
    username: string | null;
    environment: ConnectionEnvironment;
    readOnly: boolean;
    lastTestedAt: string | null;
    accountWarnings: AccountWarning[];
    canManage: boolean;
};

export type DriverOption = {
    value: DatabaseDriver;
    label: string;
    minimumVersion: string;
    defaultPort: number | null;
    sslModes: { value: string; label: string }[];
};

export type EnvironmentOption = {
    value: ConnectionEnvironment;
    label: string;
    readOnlyByDefault: boolean;
};

export type EditableConnection = {
    id: string;
    name: string;
    group: string | null;
    driver: DatabaseDriver;
    host: string | null;
    port: number | null;
    database: string | null;
    allDatabases: boolean;
    username: string | null;
    hasPassword: boolean;
    storePassword: boolean;
    sqlitePath: string | null;
    sslmode: string;
    environment: ConnectionEnvironment;
    readOnly: boolean;
};

export type TestStep = {
    key: string;
    label: string;
    state: 'ok' | 'warning' | 'failed' | 'skipped';
    detail: string | null;
};

export type ConnectionTestResult = {
    ok: boolean;
    steps: TestStep[];
    durationMs: number;
    version: string | null;
    testedAt: string;
    warnings: AccountWarning[];
    problem: {
        kind: string;
        title: string;
        explanation: string;
        detail: string | null;
        code: string | null;
        serverMessage: string | null;
    } | null;
};
