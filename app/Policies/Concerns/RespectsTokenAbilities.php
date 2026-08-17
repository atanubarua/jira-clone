<?php

namespace App\Policies\Concerns;

use App\Contracts\HasTokenAbilities;
use App\Models\User;

/**
 * Lets policies tolerate token-scoped authentication (SPEC section 4, rule 5).
 *
 * A policy must never assume the caller holds a full session. When the request
 * is authenticated by an API token, that token's abilities further constrain
 * what the user may do - a token can only ever be a SUBSET of its owner's
 * permissions, never a superset.
 *
 * Phase 7 turns this on by having User implement HasTokenAbilities via
 * Sanctum's HasApiTokens. No policy changes.
 */
trait RespectsTokenAbilities
{
    protected function tokenAllows(User $user, string $ability): bool
    {
        // No token support (Phase 1) or a plain session request: there is no
        // token to narrow what the user may do.
        if (! $user instanceof HasTokenAbilities) {
            return true;
        }

        if ($user->currentAccessToken() === null) {
            return true;
        }

        return $user->tokenCan($ability);
    }
}
