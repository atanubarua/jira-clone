export type WorkspaceRole = 'owner' | 'admin' | 'member' | 'guest';

export type MembershipStatus = 'active' | 'deactivated';

/** Minimal shape used by the workspace switcher. */
export type WorkspaceSummary = {
    id: string;
    name: string;
    slug: string;
};

/** The workspace resolved for the current request, plus the viewer's role in it. */
export type CurrentWorkspace = WorkspaceSummary & {
    role: WorkspaceRole | null;
};

export type WorkspaceMember = {
    id: string;
    role: WorkspaceRole;
    status: MembershipStatus;
    joinedAt: string | null;
    user: {
        id: string;
        name: string;
        email: string;
    };
};

export type PendingInvitation = {
    id: string;
    email: string;
    role: WorkspaceRole;
    expiresAt: string;
};
