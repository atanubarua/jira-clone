<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Issues a workspace invitation (SPEC Module 1, rules 8 and 9).
 *
 * Returns the plaintext token, which is the only time it exists outside the
 * invite URL - what is stored is its hash.
 */
class InviteMember
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{invitation: Invitation, token: string}
     */
    public function handle(
        Workspace $workspace,
        User $invitedBy,
        string $email,
        WorkspaceRole $role,
    ): array {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($workspace, $invitedBy, $email, $role): array {
            $token = Invitation::generateToken();

            $invitation = $this->tenant->runFor($workspace, function () use (
                $workspace, $invitedBy, $email, $role, $token
            ): Invitation {
                // One live invitation per email per workspace. MySQL cannot
                // express that as a partial unique index, so re-inviting
                // refreshes the existing invitation (new token, new expiry)
                // rather than creating a second one.
                $existing = Invitation::query()->live()->where('email', $email)->first();

                if ($existing instanceof Invitation) {
                    $existing->forceFill([
                        'role' => $role->value,
                        'token_hash' => Invitation::hashToken($token),
                        'invited_by_id' => $invitedBy->getKey(),
                        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
                    ])->save();

                    return $existing;
                }

                return Invitation::create([
                    'workspace_id' => $workspace->getKey(),
                    'email' => $email,
                    'role' => $role,
                    'token_hash' => Invitation::hashToken($token),
                    'invited_by_id' => $invitedBy->getKey(),
                    'expires_at' => now()->addDays(Invitation::TTL_DAYS),
                ]);
            });

            return ['invitation' => $invitation, 'token' => $token];
        });
    }
}
