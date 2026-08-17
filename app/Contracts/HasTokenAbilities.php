<?php

namespace App\Contracts;

/**
 * Implemented by an authenticatable whose requests may be scoped by an API
 * token (SPEC section 4, rule 5).
 *
 * Phase 1 has no tokens, so User does not implement this yet and policies see
 * an unconstrained session. Phase 7 adds Sanctum's HasApiTokens to User and
 * declares this interface - the shape below matches Sanctum's, so no policy
 * needs to change.
 */
interface HasTokenAbilities
{
    /**
     * The token authenticating the current request, or null for session auth.
     */
    public function currentAccessToken(): ?object;

    /**
     * Whether the current token carries the given ability.
     */
    public function tokenCan(string $ability): bool;
}
