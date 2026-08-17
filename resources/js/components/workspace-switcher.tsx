import { Link, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

/**
 * Switching workspace is a session context change, not a re-login: it is just
 * a link to the other tenant's URL.
 */
export function WorkspaceSwitcher() {
    const { workspaces, currentWorkspace } = usePage().props;

    if (workspaces.length === 0) {
        return null;
    }

    const active = currentWorkspace ?? workspaces[0];

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                                <span className="text-xs font-semibold">
                                    {active.name.slice(0, 2).toUpperCase()}
                                </span>
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-medium">
                                    {active.name}
                                </span>
                                {currentWorkspace?.role ? (
                                    <span className="truncate text-xs capitalize text-muted-foreground">
                                        {currentWorkspace.role}
                                    </span>
                                ) : null}
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
                        align="start"
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Workspaces
                        </DropdownMenuLabel>

                        {workspaces.map((workspace) => (
                            <DropdownMenuItem key={workspace.id} asChild>
                                <Link href={`/w/${workspace.slug}`}>
                                    <span className="flex-1 truncate">
                                        {workspace.name}
                                    </span>
                                    {workspace.id === active.id ? (
                                        <Check className="size-4" />
                                    ) : null}
                                </Link>
                            </DropdownMenuItem>
                        ))}

                        <DropdownMenuSeparator />

                        <DropdownMenuItem asChild>
                            <Link href="/dashboard">
                                <Plus className="size-4" />
                                <span>All workspaces</span>
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
