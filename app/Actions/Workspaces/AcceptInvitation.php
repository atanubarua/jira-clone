<?php

namespace App\Actions\Workspaces;

use App\Enums\MembershipStatus;
use App\Exceptions\InvalidInvitationException;
use App\Models\Invitation;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a workspace invitation (SPEC Module 1, rule 8).
 *
 * Legitimately cross-tenant: the invitee is not a member of anything yet, so
 * there is no tenant to resolve from a URL. Token lookup therefore runs
 * through TenantContext::runWithout(), which is exactly what that escape
 * hatch exists for.
 */
class AcceptInvitation
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Resolve an invitation from its plaintext token, or fail.
     *
     * @throws InvalidInvitationException
     */
    public function resolve(string $token): Invitation
    {
        $invitation = $this->tenant->runWithout(
            fn (): ?Invitation => Invitation::query()
                ->where('token_hash', Invitation::hashToken($token))
                ->with('workspace')
                ->first()
        );

        if (! $invitation instanceof Invitation || ! $invitation->tokenMatches($token)) {
            throw InvalidInvitationException::notFound();
        }

        if ($invitation->accepted_at) {
            throw InvalidInvitationException::alreadyAccepted();
        }

        if ($invitation->revoked_at) {
            throw InvalidInvitationException::revoked();
        }

        if ($invitation->isExpired()) {
            throw InvalidInvitationException::expired();
        }

        return $invitation;
    }

    /**
     * Accept for an already-authenticated user.
     *
     * @throws InvalidInvitationException
     */
    public function forExistingUser(string $token, User $user): WorkspaceMember
    {
        return DB::transaction(function () use ($token, $user): WorkspaceMember {
            $invitation = $this->resolve($token);

            return $this->attach($invitation, $user);
        });
    }

    /**
     * Accept for an email with no account yet: the user is created in the same
     * transaction as the membership.
     *
     * @param  array{name: string, password: string}  $attributes
     *
     * @throws InvalidInvitationException
     */
    public function forNewUser(string $token, array $attributes): WorkspaceMember
    {
        return DB::transaction(function () use ($token, $attributes): WorkspaceMember {
            $invitation = $this->resolve($token);

            $user = User::create([
                'name' => $attributes['name'],
                'email' => $invitation->email,
                'password' => $attributes['password'],
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            return $this->attach($invitation, $user);
        });
    }

    private function attach(Invitation $invitation, User $user): WorkspaceMember
    {
        $workspace = $invitation->workspace;

        return $this->tenant->runFor($workspace, function () use ($invitation, $user, $workspace): WorkspaceMember {
            $membership = WorkspaceMember::query()
                ->where('user_id', $user->getKey())
                ->first();

            if (! $membership instanceof WorkspaceMember) {
                $membership = WorkspaceMember::create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $user->getKey(),
                    'role' => $invitation->role,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]);
            }

            // Single-use: consumed even if the user was already a member, so a
            // leaked link cannot be replayed.
            $invitation->forceFill(['accepted_at' => now()])->save();

            $user->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

            return $membership;
        });
    }
}
