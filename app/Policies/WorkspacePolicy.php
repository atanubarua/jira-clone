<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\RespectsTokenAbilities;

/**
 * The workspace half of the permission model (SPEC section 4).
 *
 * Project-level roles arrive in Phase 2 and slot in alongside this class -
 * effective project permission is max(workspace-implied, explicit project
 * membership), with guests never elevated implicitly.
 *
 * Every method takes the acting User explicitly. Nothing here reads the Auth
 * facade, so the same policy serves session and token-authenticated callers.
 */
class WorkspacePolicy
{
    use RespectsTokenAbilities;

    /**
     * Can the user see this workspace exists at all?
     *
     * Anything false here must surface as 404, never 403 - a 403 confirms the
     * workspace exists and leaks tenant names.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, 'workspace:view', fn (WorkspaceRole $role): bool => true);
    }

    /**
     * Members get a read-only view of settings; guests get nothing.
     */
    public function viewSettings(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'workspace:view',
            fn (WorkspaceRole $role): bool => ! $role->isGuest(),
        );
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'workspace:update',
            fn (WorkspaceRole $role): bool => $role->isAdministrative(),
        );
    }

    public function invite(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'workspace:invite',
            fn (WorkspaceRole $role): bool => $role->isAdministrative(),
        );
    }

    /**
     * Inviting or promoting someone to admin is itself an admin capability.
     */
    public function inviteAdmin(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'workspace:invite',
            fn (WorkspaceRole $role): bool => $role->isAdministrative(),
        );
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'workspace:manage-members',
            fn (WorkspaceRole $role): bool => $role->isAdministrative(),
        );
    }

    public function transferOwnership(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'workspace:transfer-ownership',
            fn (WorkspaceRole $role): bool => $role === WorkspaceRole::Owner,
        );
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'workspace:delete',
            fn (WorkspaceRole $role): bool => $role === WorkspaceRole::Owner,
        );
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'workspace:billing',
            fn (WorkspaceRole $role): bool => $role === WorkspaceRole::Owner,
        );
    }

    /**
     * Guests cannot create projects; everyone else can (SPEC section 4).
     */
    public function createProject(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'project:create',
            fn (WorkspaceRole $role): bool => ! $role->isGuest(),
        );
    }

    public function manageLabels(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'label:manage',
            fn (WorkspaceRole $role): bool => ! $role->isGuest(),
        );
    }

    /**
     * Listing every project in the workspace, including private ones.
     *
     * Owners and admins can reach private projects deliberately (SPEC A-25):
     * someone must be able to recover a private project whose only member has
     * left. This is disclosed in the UI rather than pretended away.
     */
    public function viewAllProjects(User $user, Workspace $workspace): bool
    {
        return $this->allows(
            $user, $workspace, 'project:view-all',
            fn (WorkspaceRole $role): bool => $role->isAdministrative(),
        );
    }

    /**
     * Shared gate: the user must be an ACTIVE member, any token must carry the
     * ability, and the role must satisfy the capability.
     *
     * @param  callable(WorkspaceRole): bool  $roleCheck
     */
    private function allows(User $user, Workspace $workspace, string $ability, callable $roleCheck): bool
    {
        $role = $user->roleIn($workspace);

        if (! $role instanceof WorkspaceRole) {
            return false;
        }

        if (! $this->tokenAllows($user, $ability)) {
            return false;
        }

        return $roleCheck($role);
    }
}
