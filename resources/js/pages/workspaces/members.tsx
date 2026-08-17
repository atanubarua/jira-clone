import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    PendingInvitation,
    WorkspaceMember,
    WorkspaceRole,
    WorkspaceSummary,
} from '@/types';

type Props = {
    workspace: Pick<WorkspaceSummary, 'name' | 'slug'>;
    members: WorkspaceMember[];
    invitations: PendingInvitation[];
    roles: WorkspaceRole[];
    can: {
        manageMembers: boolean;
        transferOwnership: boolean;
    };
};

export default function Members({
    workspace,
    members,
    invitations,
    roles,
    can,
}: Props) {
    const { flash } = usePage().props;
    const [inviteRole, setInviteRole] = useState<WorkspaceRole>('member');

    return (
        <>
            <Head title={`Members - ${workspace.name}`} />

            <div className="flex h-full flex-1 flex-col gap-8 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Members
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Who can reach {workspace.name}, and what they may do.
                    </p>
                </div>

                {/*
                  The UI hides what the user cannot do, but this is cosmetic
                  only - the policy is the enforcement (CLAUDE.md rule 7).
                */}
                {can.manageMembers ? (
                    <section className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 className="text-sm font-medium">Invite someone</h2>

                        <Form
                            action={`/w/${workspace.slug}/invitations`}
                            method="post"
                            className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="flex-1">
                                        <Label htmlFor="email">
                                            Email address
                                        </Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            placeholder="teammate@example.com"
                                        />
                                        {errors.email ? (
                                            <p className="mt-1 text-sm text-destructive">
                                                {errors.email}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="w-full sm:w-40">
                                        <Label htmlFor="role">Role</Label>
                                        <input
                                            type="hidden"
                                            name="role"
                                            value={inviteRole}
                                        />
                                        <Select
                                            value={inviteRole}
                                            onValueChange={(value) =>
                                                setInviteRole(
                                                    value as WorkspaceRole,
                                                )
                                            }
                                        >
                                            <SelectTrigger id="role">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roles.map((role) => (
                                                    <SelectItem
                                                        key={role}
                                                        value={role}
                                                    >
                                                        <span className="capitalize">
                                                            {role}
                                                        </span>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        Send invite
                                    </Button>
                                </>
                            )}
                        </Form>

                        {flash.invitationUrl ? (
                            <p className="mt-3 rounded-md bg-muted p-3 text-xs break-all">
                                Invitation link (email delivery arrives in
                                Phase 4):{' '}
                                <code>{flash.invitationUrl}</code>
                            </p>
                        ) : null}
                    </section>
                ) : null}

                <section>
                    <h2 className="text-sm font-medium">
                        Members ({members.length})
                    </h2>

                    <div className="mt-3 overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Name</th>
                                    <th className="p-3 font-medium">Role</th>
                                    <th className="p-3 font-medium">Status</th>
                                    {can.manageMembers ? (
                                        <th className="p-3 font-medium">
                                            Actions
                                        </th>
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((member) => (
                                    <tr
                                        key={member.id}
                                        className="border-t border-sidebar-border/70 dark:border-sidebar-border"
                                    >
                                        <td className="p-3">
                                            <div className="font-medium">
                                                {member.user.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {member.user.email}
                                            </div>
                                        </td>
                                        <td className="p-3 capitalize">
                                            {member.role}
                                        </td>
                                        <td className="p-3">
                                            <span
                                                className={
                                                    member.status === 'active'
                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {member.status}
                                            </span>
                                        </td>
                                        {can.manageMembers ? (
                                            <td className="p-3">
                                                {member.role === 'owner' ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        Transfer ownership first
                                                    </span>
                                                ) : (
                                                    <Form
                                                        action={`/w/${workspace.slug}/members/${member.id}`}
                                                        method="patch"
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <>
                                                                <input
                                                                    type="hidden"
                                                                    name="status"
                                                                    value={
                                                                        member.status ===
                                                                        'active'
                                                                            ? 'deactivated'
                                                                            : 'active'
                                                                    }
                                                                />
                                                                <Button
                                                                    type="submit"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    {member.status ===
                                                                    'active'
                                                                        ? 'Deactivate'
                                                                        : 'Reactivate'}
                                                                </Button>
                                                            </>
                                                        )}
                                                    </Form>
                                                )}
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {can.manageMembers && invitations.length > 0 ? (
                    <section>
                        <h2 className="text-sm font-medium">
                            Pending invitations ({invitations.length})
                        </h2>

                        <div className="mt-3 overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3 font-medium">
                                            Email
                                        </th>
                                        <th className="p-3 font-medium">
                                            Role
                                        </th>
                                        <th className="p-3 font-medium">
                                            Expires
                                        </th>
                                        <th className="p-3 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {invitations.map((invitation) => (
                                        <tr
                                            key={invitation.id}
                                            className="border-t border-sidebar-border/70 dark:border-sidebar-border"
                                        >
                                            <td className="p-3">
                                                {invitation.email}
                                            </td>
                                            <td className="p-3 capitalize">
                                                {invitation.role}
                                            </td>
                                            <td className="p-3 text-muted-foreground">
                                                {new Date(
                                                    invitation.expiresAt,
                                                ).toLocaleDateString()}
                                            </td>
                                            <td className="p-3">
                                                <Form
                                                    action={`/w/${workspace.slug}/invitations/${invitation.id}`}
                                                    method="delete"
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Revoke
                                                        </Button>
                                                    )}
                                                </Form>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}
            </div>
        </>
    );
}
