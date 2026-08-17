<?php

namespace Tests\Support;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;

/**
 * Two fully populated workspaces plus an acting user who belongs to exactly
 * one of them.
 *
 * Every isolation case is the same shape: act as `$user` (owner of `$home`)
 * and reach for something in `$foreign`. The answer must always be 404.
 */
final class IsolationFixture
{
    public function __construct(
        public readonly User $user,
        public readonly Workspace $home,
        public readonly Workspace $foreign,
        public readonly WorkspaceMember $foreignMember,
        public readonly Invitation $foreignInvitation,
        public readonly WorkspaceMember $homeMember,
    ) {}

    public static function make(): self
    {
        $tenant = app(TenantContext::class);

        $user = User::factory()->create();
        $home = Workspace::factory()->create(['owner_id' => $user->id]);

        $tenant->runFor($home, fn (): WorkspaceMember => WorkspaceMember::factory()
            ->owner()
            ->create(['workspace_id' => $home->id, 'user_id' => $user->id]));

        // A second, entirely unrelated tenant.
        $outsider = User::factory()->create();
        $foreign = Workspace::factory()->create(['owner_id' => $outsider->id]);

        [$foreignMember, $foreignInvitation] = $tenant->runFor($foreign, fn (): array => [
            WorkspaceMember::factory()->owner()->create([
                'workspace_id' => $foreign->id,
                'user_id' => $outsider->id,
            ]),
            Invitation::factory()->create([
                'workspace_id' => $foreign->id,
                'invited_by_id' => $outsider->id,
            ]),
        ]);

        // A second member in the home workspace, so role-change cases have a
        // legitimate target that is not the owner.
        $homeColleague = $tenant->runFor($home, fn (): WorkspaceMember => WorkspaceMember::factory()
            ->create(['workspace_id' => $home->id, 'user_id' => User::factory()->create()->id]));

        return new self(
            user: $user,
            home: $home,
            foreign: $foreign,
            foreignMember: $foreignMember,
            foreignInvitation: $foreignInvitation,
            homeMember: $homeColleague,
        );
    }

    /**
     * Give the acting user a specific role in their home workspace.
     */
    public function actAs(WorkspaceRole $role): self
    {
        app(TenantContext::class)->runFor($this->home, function () use ($role): void {
            WorkspaceMember::query()
                ->where('user_id', $this->user->id)
                ->first()
                ?->forceFill(['role' => $role->value])
                ->saveQuietly();
        });

        return $this;
    }
}
