<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceRole::Member,
            'token_hash' => Invitation::hashToken(Invitation::generateToken()),
            'invited_by_id' => User::factory(),
            'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        ];
    }

    /**
     * Use a known plaintext token so tests can drive the accept flow.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (): array => [
            'token_hash' => Invitation::hashToken($token),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'accepted_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }

    public function role(WorkspaceRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }
}
