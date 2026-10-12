<?php

declare(strict_types=1);

use App\Domain\Auth\Permissions;
use App\Domain\Auth\Role;
use Tests\Support\Factory;

/*
 * Every /api route names the permission it needs, and a level without it gets
 * 403 -- walked for every route and every level (docs/access-levels.md). The
 * table in tests/Support/access-routes.php is the expectation, written apart
 * from the route files; a route the table does not list fails the first test,
 * so none can be added unguarded.
 */

require_once dirname(__DIR__) . '/Support/access-routes.php';

it('names a permission for every /api route, and no route is missing from the table', function (): void {
    $expected = [...array_keys(guardedRoutes()), ...UNGUARDED];
    sort($expected);

    expect(registeredApiRoutes())->toBe($expected);
});

it('refuses each level every route it lacks the permission for, and only those', function (?Role $role): void {
    $this->actingAs(Factory::createUser($role));
    $wrong = [];

    foreach (guardedRoutes() as $route => $permission) {
        [$method, $pattern] = explode(' ', $route, 2);

        $status = $this->request($method, concrete($pattern))->getStatusCode();
        $refused = $status === 403;
        $allowed = Permissions::allows($role, $permission);

        if ($refused === $allowed) {
            $wrong[] = sprintf(
                '%s as %s: %d, but the level %s %s',
                $route,
                $role->value ?? 'no level',
                $status,
                $allowed ? 'holds' : 'lacks',
                $permission->value,
            );
        }
    }

    expect($wrong)->toBe([]);
})->with([
    'owner' => [Role::Owner],
    'administrator' => [Role::Administrator],
    'user' => [Role::User],
    'no level' => [null],
]);

it('answers 401, not 403, to somebody not signed in', function (): void {
    foreach (array_keys(guardedRoutes()) as $route) {
        [$method, $pattern] = explode(' ', $route, 2);

        expect($this->request($method, concrete($pattern))->getStatusCode())->toBe(401, $route);
    }
});
