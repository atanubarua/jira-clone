<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceRole;
use App\Exceptions\OwnershipException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * Transfers workspace ownership (SPEC Module 1, rule 5).
 *
 * The successor is promoted and the incumbent demoted to admin inside one
 * transaction, so "exactly one owner" holds at every commit boundary.
 */
class TransferOwnership
{
    public function handle(Workspace $workspace, User $newOwner): void
    {
        DB::transaction(function () use ($workspace, $newOwner): void {
            $current = $workspace->ownerMembership();
            $target = $newOwner->membershipFor($workspace);

            if (! $target instanceof WorkspaceMember || ! $target->isActive()) {
                throw OwnershipException::targetNotActiveMember();
            }

            if ($current instanceof WorkspaceMember && $current->is($target)) {
                return;
            }

            // Demote first, using forceFill to step past the model guard that
            // (correctly) refuses to mutate an owner membership casually. The
            // guard protects against accidental demotion; this is the one
            // sanctioned path.
            if ($current instanceof WorkspaceMember) {
                $current->forceFill(['role' => WorkspaceRole::Admin->value])->saveQuietly();
            }

            $target->forceFill(['role' => WorkspaceRole::Owner->value])->saveQuietly();

            $workspace->forceFill(['owner_id' => $newOwner->getKey()])->save();
        });
    }
}
