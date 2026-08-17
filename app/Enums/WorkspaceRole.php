<?php

namespace App\Enums;

/**
 * Workspace-level role (SPEC section 4).
 *
 * This answers "what class of user are you here?". Project-level membership
 * (Phase 2) answers "which projects, and what may you do in them?".
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Guest = 'guest';

    /**
     * Roles that may be handed out via an invitation.
     *
     * Ownership is never granted by invite - it is transferred by the current
     * owner, and there is exactly one owner at all times.
     */
    /** @return array<int, self> */
    public static function invitable(): array
    {
        return [self::Admin, self::Member, self::Guest];
    }

    /** @return array<int, string> */
    public static function invitableValues(): array
    {
        return array_map(fn (self $role): string => $role->value, self::invitable());
    }

    /**
     * Administrative roles: they run the workspace.
     */
    public function isAdministrative(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /**
     * Guests never receive implicit access to anything (SPEC section 4). Their
     * access is exactly the union of their explicit project memberships.
     */
    public function isGuest(): bool
    {
        return $this === self::Guest;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
