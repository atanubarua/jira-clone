import type { Auth } from '@/types/auth';
import type { CurrentWorkspace, WorkspaceSummary } from '@/types/workspace';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** Active memberships only - a deactivated one disappears here. */
            workspaces: WorkspaceSummary[];
            /** Null outside the /w/{workspace} tenant boundary. */
            currentWorkspace: CurrentWorkspace | null;
            flash: {
                status: string | null;
                invitationUrl: string | null;
            };
            [key: string]: unknown;
        };
    }
}
