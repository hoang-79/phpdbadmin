import type { ConnectionEnvironment, DatabaseDriver } from './connections';

/** Schemavergleich (S-08), wie CompareController ihn liefert. */
export type CompareConnectionOption = {
    id: string;
    name: string;
    group: string | null;
    driverLabel: string;
    environment: ConnectionEnvironment;
};

export type CompareProblem = {
    kind: string;
    title: string;
    explanation: string;
    detail?: string | null;
    passwordUrl?: string;
};

export type CompareSide = {
    connection: {
        id: string;
        name: string;
        driver: DatabaseDriver;
        driverLabel: string;
        environment: ConnectionEnvironment;
    } | null;
    databases: string[] | null;
    database: string | null;
    schemas: string[];
    schema: string | null;
    problem: CompareProblem | null;
};

export type CompareStatus = 'added' | 'removed' | 'changed';

export type CompareItem = {
    kind: 'column' | 'index' | 'foreignKey';
    name: string;
    status: CompareStatus;
    changes: string[];
    source: string | null;
    target: string | null;
};

export type CompareTable = {
    name: string;
    status: CompareStatus;
    items: CompareItem[];
};

export type CompareResult =
    | { error: string }
    | {
          error: null;
          sameDriver: boolean;
          identical: number;
          counts: {
              tables: number;
              columns: number;
              indexes: number;
              foreignKeys: number;
          };
          tables: CompareTable[];
          script: string | null;
          statements: number;
          notes: string[];
          editorUrl: string | null;
          fileName: string;
      };
