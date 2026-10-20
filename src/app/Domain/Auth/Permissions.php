<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Who may do what: the access grid (docs/access-levels.md). One table, asked
 * by every route and by the frontend, so a level that cannot do something is
 * refused it everywhere and never shown it.
 *
 * The Owner holds every permission, so only the two lower levels are listed.
 */
final class Permissions
{
    /** @var array<string,list<Permission>> */
    private const array GRID = [
        'administrator' => [
            Permission::Home,
            Permission::SettingsView,
            Permission::QueueManage,
            Permission::ContentEdit,
            Permission::ContentPublish,
            Permission::InvitationsManage,
            Permission::FormsManage,
        ],
        'user' => [
            Permission::Home,
        ],
    ];

    public static function allows(?Role $role, Permission $permission): bool
    {
        return in_array($permission, self::for($role), true);
    }

    /** @return list<Permission> */
    public static function for(?Role $role): array
    {
        return match ($role) {
            null => [],
            Role::Owner => Permission::cases(),
            default => self::GRID[$role->value],
        };
    }

    /** @return list<string> the values, for the API */
    public static function valuesFor(?Role $role): array
    {
        return array_map(static fn (Permission $permission): string => $permission->value, self::for($role));
    }
}
