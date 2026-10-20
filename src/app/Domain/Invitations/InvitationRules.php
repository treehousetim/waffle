<?php

declare(strict_types=1);

namespace App\Domain\Invitations;

use App\Domain\Auth\Role;

/**
 * Who may invite whom (docs/invitations.md, 4.): an owner invites
 * administrators and users, an administrator invites users, and nobody
 * invites an owner.
 */
final class InvitationRules
{
    public const string EXPIRES_IN = '+7 days';

    /** @return list<Role> the levels this inviter may invite people at */
    public static function levelsFor(?Role $inviter): array
    {
        return match ($inviter) {
            Role::Owner => [Role::Administrator, Role::User],
            Role::Administrator => [Role::User],
            default => [],
        };
    }

    public static function mayInvite(?Role $inviter, Role $level): bool
    {
        return in_array($level, self::levelsFor($inviter), true);
    }
}
