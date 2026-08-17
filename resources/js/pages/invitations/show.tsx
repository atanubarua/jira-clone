import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { WorkspaceRole } from '@/types';

type Props = {
    token: string;
    workspaceName: string;
    email: string;
    role: WorkspaceRole;
    hasAccount: boolean;
    isAuthenticated: boolean;
};

/**
 * Redeeming an invitation. Reached outside any tenant, since the invitee does
 * not belong to the workspace yet.
 */
export default function InvitationShow({
    token,
    workspaceName,
    email,
    role,
    hasAccount,
    isAuthenticated,
}: Props) {
    return (
        <>
            <Head title={`Join ${workspaceName}`} />

            <div className="flex flex-col gap-6">
                <div className="text-center">
                    <h1 className="text-xl font-semibold">
                        Join {workspaceName}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        You have been invited as{' '}
                        <span className="capitalize">{role}</span> using{' '}
                        <span className="font-medium">{email}</span>.
                    </p>
                </div>

                <Form action={`/invitations/${token}`} method="post">
                    {({ processing, errors }) => (
                        <div className="flex flex-col gap-4">
                            {errors.token ? (
                                <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                    {errors.token}
                                </p>
                            ) : null}

                            {/*
                              An authenticated visitor just accepts. Everyone
                              else sets up the account the invitation creates -
                              the email comes from the invitation, never input.
                            */}
                            {isAuthenticated ? null : (
                                <>
                                    <div>
                                        <Label htmlFor="name">Your name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            autoFocus
                                            autoComplete="name"
                                        />
                                        {errors.name ? (
                                            <p className="mt-1 text-sm text-destructive">
                                                {errors.name}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div>
                                        <Label htmlFor="password">
                                            {hasAccount
                                                ? 'Password'
                                                : 'Choose a password'}
                                        </Label>
                                        <Input
                                            id="password"
                                            name="password"
                                            type="password"
                                            required
                                            autoComplete="new-password"
                                        />
                                        {errors.password ? (
                                            <p className="mt-1 text-sm text-destructive">
                                                {errors.password}
                                            </p>
                                        ) : null}
                                    </div>
                                </>
                            )}

                            <Button type="submit" disabled={processing}>
                                Accept invitation
                            </Button>
                        </div>
                    )}
                </Form>
            </div>
        </>
    );
}
