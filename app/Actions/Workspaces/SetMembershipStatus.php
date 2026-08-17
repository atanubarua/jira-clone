<?php

namespace App\Actions\Workspaces;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\OwnershipException;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a membership (SPEC Module 1, rule 7).
 *
 * Deactivation preserves everything the user authored and every issue assigned
 * to them; it removes access only.
 *
 * Note on "sessions are invalidated": membership is per-workspace, so a global
 * logout would be wrong - a user deactivated in workspace A may still be an
 * active member of workspace B. Access is revoked at the tenant boundary
 * instead: ResolveWorkspace requires an ACTIVE membership on every request, so
 * revocation takes effect on the deactivated user's very next request without
 * disturbing their other workspaces.
 */
class SetMembershipStatus
{
    public function handle(WorkspaceMember $member, MembershipStatus $status): WorkspaceMember
    {
        if ($member->role === WorkspaceRole::Owner) {
            throw OwnershipException::cannotRemoveOwner();
        }

        return DB::transaction(function () use ($member, $status): WorkspaceMember {
            $member->forceFill(['status' => $status->value])->saveQuietly();

            if (! $status->isActive()) {
                // Don't strand them on a workspace they can no longer open.
                $member->user()->where('last_workspace_id', $member->workspace_id)
                    ->update(['last_workspace_id' => null]);
            }

            return $member->refresh();
        });
    }
}
