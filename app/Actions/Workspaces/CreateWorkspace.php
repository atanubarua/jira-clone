<?php

namespace App\Actions\Workspaces;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a workspace and its single owner membership.
 *
 * Both rows are written in one transaction: a failure must not leave an
 * ownerless workspace or a user with no workspace (SPEC Module 1, rule 11).
 */
class CreateWorkspace
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name): Workspace {
            $workspace = Workspace::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_id' => $owner->getKey(),
            ]);

            // The membership is tenant-owned, and no tenant is bound yet during
            // registration - bind the freshly created workspace explicitly.
            $this->tenant->runFor($workspace, function () use ($workspace, $owner): void {
                WorkspaceMember::create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $owner->getKey(),
                    'role' => WorkspaceRole::Owner,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]);
            });

            $owner->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

            return $workspace;
        });
    }

    /**
     * Slugs are globally unique and immutable, so resolve collisions at
     * creation time rather than failing the registration.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'workspace';
        }

        $base = Str::limit($base, 50, '');
        $slug = $base;

        while (Workspace::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
