<?php

namespace App\Enums;

/**
 * Membership status (SPEC Module 1, rule 7).
 *
 * Deactivation is the only removal mechanism for a user who has authored
 * anything: assignments and comments remain, access does not.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Deactivated = 'deactivated';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
