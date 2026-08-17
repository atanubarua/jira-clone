import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';

export default function Dashboard() {
    const { workspaces, auth } = usePage().props;

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex h-full flex-1 flex-col gap-8 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Welcome back, {auth.user.name.split(' ')[0]}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pick a workspace to work in.
                    </p>
                </div>

                <section>
                    <h2 className="text-sm font-medium">
                        Your workspaces ({workspaces.length})
                    </h2>

                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {workspaces.map((workspace) => (
                            <Link
                                key={workspace.id}
                                href={`/w/${workspace.slug}`}
                                className="group flex items-center justify-between rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-accent dark:border-sidebar-border"
                            >
                                <div className="min-w-0">
                                    <p className="truncate font-medium">
                                        {workspace.name}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        /w/{workspace.slug}
                                    </p>
                                </div>
                                <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                            </Link>
                        ))}
                    </div>
                </section>

                <section className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 className="text-sm font-medium">Create a workspace</h2>
                    <p className="text-xs text-muted-foreground">
                        You become its owner. Workspaces are fully isolated from
                        one another.
                    </p>

                    <Form
                        action="/workspaces"
                        method="post"
                        className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="flex-1">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        placeholder="Acme Engineering"
                                    />
                                    {errors.name ? (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.name}
                                        </p>
                                    ) : null}
                                </div>

                                <Button type="submit" disabled={processing}>
                                    <Plus className="size-4" />
                                    Create
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
