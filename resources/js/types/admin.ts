import type {
    ConnectionEnvironment,
    ConnectionPermission,
} from './connections';
import type { UserRole } from './auth';

/** Zähler der Reiter in der Verwaltung (Wireframe 14). */
export type AdminTabs = {
    accounts: number;
    invitations: number;
    invitationDays: number;
};

export type AdminAccount = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    roleLabel: string;
    isAdmin: boolean;
    disabled: boolean;
    lastLoginAt: string | null;
    hasPasskeys: boolean;
    isSelf: boolean;
};

export type AdminAccountDetail = AdminAccount & {
    createdAt: string | null;
    can: {
        update: boolean;
        changeRole: boolean;
        disable: boolean;
        delete: boolean;
    };
};

/** Eine Zeile in «Rechte je Verbindung». */
export type ConnectionRight = {
    id: string;
    name: string;
    environment: ConnectionEnvironment;
    driverLabel: string;
    serverVersion: string | null;
    readOnly: boolean;
    shared: boolean;
    ownerName: string;
    isOwner: boolean;
    permission: ConnectionPermission | null;
    editable: boolean;
};

export type PermissionOption = {
    value: ConnectionPermission;
    label: string;
    description: string;
};

export type RoleOption = {
    value: UserRole;
    label: string;
};

export type AdminInvitation = {
    id: number;
    email: string;
    role: UserRole;
    roleLabel: string;
    invitedBy: string | null;
    createdAt: string | null;
    expiresAt: string;
    acceptedAt: string | null;
    state: 'open' | 'accepted' | 'expired';
};
