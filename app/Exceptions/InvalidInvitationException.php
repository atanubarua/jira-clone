<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an invitation token cannot be redeemed.
 *
 * The messages are deliberately non-specific about whether the token ever
 * existed - an attacker probing tokens learns nothing from "not found" versus
 * "expired" beyond what they already supplied.
 */
class InvalidInvitationException extends RuntimeException
{
    public static function notFound(): self
    {
        return new self('This invitation link is not valid.');
    }

    public static function expired(): self
    {
        return new self('This invitation has expired. Ask for a new one.');
    }

    public static function alreadyAccepted(): self
    {
        return new self('This invitation has already been used.');
    }

    public static function revoked(): self
    {
        return new self('This invitation has been revoked.');
    }
}
