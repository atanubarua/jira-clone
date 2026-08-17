import { Head, Link } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { WorkspaceSummary } from '@/types';

type Props = {
    workspace: WorkspaceSummary;
    memberCount: number;
};

export default function WorkspaceShow({ workspace, memberCount }: Props) {
    return (
        <>
            <Head title={workspace.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {workspace.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            /w/{workspace.slug}
                        </p>
                    </div>

                    <Button variant="outline" asChild>
                        <Link href={`/w/${workspace.slug}/members`}>
                            <Users className="size-4" />
                            Members
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <p className="text-sm text-muted-foreground">
                            Active members
                        </p>
                        <p className="mt-1 text-2xl font-semibold">
                            {memberCount}
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-dashed border-sidebar-border/70 p-8 text-center dark:border-sidebar-border">
                    <p className="text-sm text-muted-foreground">
                        Projects and issues arrive in Phase 2 and Phase 3. This
                        workspace, its members and its permissions are ready.
                    </p>
                </div>
            </div>
        </>
    );
}
