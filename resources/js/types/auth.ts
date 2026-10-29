export type UserRole = 'admin' | 'member';

/** Angemeldetes Konto, wie HandleInertiaRequests es teilt — nie das ganze Modell. */
export type User = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    isAdmin: boolean;
    /** KI-Hilfe (Q-08): null, wenn die Installation sie nicht eingeschaltet hat. */
    ai: { hasKey: boolean } | null;
};

export type Auth = {
    user: User | null;
};

/** Aktive Sitzung (A-08); `key` ist ein HMAC der Sitzungskennung, nie die Kennung selbst. */
export type ActiveSession = {
    key: string;
    current: boolean;
    ip: string | null;
    browser: string | null;
    os: string | null;
    lastActiveAt: string;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    createdAt: string | null;
    lastUsedAt: string | null;
};
