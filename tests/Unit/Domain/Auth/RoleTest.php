<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use Delight\Auth\Role as DelightRole;

it('mirrors the Delight\Auth bitmask constants it duplicates', function (): void {
    // Domain/ may not reference a vendor namespace, so Role::mask() hardcodes
    // these values. This test is the thing that stops them drifting.
    expect(Role::Owner->mask())->toBe(DelightRole::ADMIN)
        ->and(Role::Administrator->mask())->toBe(DelightRole::MANAGER)
        ->and(Role::User->mask())->toBe(DelightRole::EMPLOYEE);
});

it('exposes the role names the API and the SPA agree on', function (): void {
    expect(Role::Owner->value)->toBe('owner')
        ->and(Role::Administrator->value)->toBe('administrator')
        ->and(Role::User->value)->toBe('user')
        ->and(Role::Administrator->label())->toBe('Administrator');
});

it('labels each level for people', function (): void {
    expect(Role::Owner->label())->toBe('Owner')
        ->and(Role::User->label())->toBe('User');
});

it('reads a single role out of a bitmask', function (): void {
    expect(Role::fromMask(Role::Owner->mask()))->toBe(Role::Owner)
        ->and(Role::fromMask(Role::Administrator->mask()))->toBe(Role::Administrator)
        ->and(Role::fromMask(Role::User->mask()))->toBe(Role::User);
});

it('prefers the highest level when a mask carries several', function (): void {
    expect(Role::fromMask(Role::Owner->mask() | Role::Administrator->mask()))->toBe(Role::Owner)
        ->and(Role::fromMask(Role::Administrator->mask() | Role::User->mask()))->toBe(Role::Administrator);
});

it('ignores bits that belong to roles the app does not use', function (): void {
    // Delight\Auth\Role::EDITOR — a real role in the library, not one of ours.
    expect(Role::fromMask(DelightRole::EDITOR))->toBeNull();
});

it('returns null for an empty mask', function (): void {
    expect(Role::fromMask(0))->toBeNull();
});
