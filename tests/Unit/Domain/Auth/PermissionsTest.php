<?php

declare(strict_types=1);

use App\Domain\Auth\Permission;
use App\Domain\Auth\Permissions;
use App\Domain\Auth\Role;

/*
 * The access grid (docs/access-levels.md). The table in that document and
 * this test say the same thing; change both together.
 */

it('gives the owner every permission there is', function (): void {
    expect(Permissions::for(Role::Owner))->toBe(Permission::cases());
});

it('gives each lower level exactly its row of the grid', function (Role $role, array $expected): void {
    expect(Permissions::valuesFor($role))->toBe($expected);
})->with([
    'administrator' => [Role::Administrator, ['home', 'settings-view']],
    'user' => [Role::User, ['home']],
]);

it('gives an account with no level nothing at all', function (): void {
    expect(Permissions::for(null))->toBe([])
        ->and(Permissions::allows(null, Permission::Home))->toBeFalse();
});

it('answers allows() from the same grid', function (): void {
    expect(Permissions::allows(Role::User, Permission::Home))->toBeTrue()
        ->and(Permissions::allows(Role::User, Permission::SettingsView))->toBeFalse()
        ->and(Permissions::allows(Role::Administrator, Permission::SettingsView))->toBeTrue()
        ->and(Permissions::allows(Role::Administrator, Permission::SettingsEdit))->toBeFalse()
        ->and(Permissions::allows(Role::Owner, Permission::SettingsEdit))->toBeTrue();
});

it('never gives a lower level something a higher one lacks', function (): void {
    // The levels are nested: whatever a user may do, an administrator may.
    $user = Permissions::for(Role::User);
    $administrator = Permissions::for(Role::Administrator);

    expect(array_diff(
        array_map(static fn (Permission $p): string => $p->value, $user),
        array_map(static fn (Permission $p): string => $p->value, $administrator),
    ))->toBe([]);
});
