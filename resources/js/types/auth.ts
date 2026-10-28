export type UserRole = 'admin' | 'member';

/** Angemeldetes Konto, wie HandleInertiaRequests es teilt — nie das ganze Modell. */
export type User = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    isAdmin: boolean;
};

export type Auth = {
    user: User | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    createdAt: string | null;
    lastUsedAt: string | null;
};
