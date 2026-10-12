<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * The access levels, and how they map onto Delight\Auth's roles_mask.
 *
 * The mask values are duplicated here as literals on purpose: Domain/ may not
 * depend on a vendor namespace. tests/Unit/Domain/Auth/RoleTest.php asserts
 * that they still equal the Delight\Auth\Role constants they mirror, so the
 * two can never drift apart silently.
 *
 * What each level may do is not decided here: it is Permissions::GRID.
 */
enum Role: string
{
    // Highest first: fromMask() returns the first one an account holds, so an
    // account with two bits is its higher level.
    case Owner = 'owner';
    case Administrator = 'administrator';
    case User = 'user';

    /**
     * Bitmask value in Delight\Auth's users.roles_mask column.
     */
    public function mask(): int
    {
        return match ($this) {
            self::Owner => 1,             // Delight\Auth\Role::ADMIN
            self::Administrator => 8192,  // Delight\Auth\Role::MANAGER
            self::User => 2048,           // Delight\Auth\Role::EMPLOYEE
        };
    }

    /** For people, not for code. */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The highest-privilege role represented in a Delight roles bitmask.
     *
     * Cases are declared most-privileged first, so the first match wins: a user
     * carrying both bits is an owner.
     */
    public static function fromMask(int $mask): ?self
    {
        foreach (self::cases() as $role) {
            if (($mask & $role->mask()) !== 0) {
                return $role;
            }
        }

        return null;
    }
}
