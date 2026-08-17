<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation would break the "exactly one owner per workspace"
 * invariant (SPEC Module 1, rules 5 and 6).
 */
class OwnershipException extends RuntimeException
{
    public static function cannotRemoveOwner(): self
    {
        return new self(
            'The workspace owner cannot be removed or deactivated. '
            .'Transfer ownership first.'
        );
    }

    public static function alreadyHasOwner(): self
    {
        return new self('A workspace has exactly one owner at all times.');
    }

    public static function targetNotActiveMember(): self
    {
        return new self('Ownership can only be transferred to an active member.');
    }
}
