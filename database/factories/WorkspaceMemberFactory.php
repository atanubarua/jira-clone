<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMember>
 */
class WorkspaceMemberFactory extends Factory
{
    protected $model = WorkspaceMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'role' => WorkspaceRole::Member,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ];
    }

    public function role(WorkspaceRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function owner(): static
    {
        return $this->role(WorkspaceRole::Owner);
    }

    public function admin(): static
    {
        return $this->role(WorkspaceRole::Admin);
    }

    public function guest(): static
    {
        return $this->role(WorkspaceRole::Guest);
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => ['status' => MembershipStatus::Deactivated]);
    }
}
