<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use Tests\Support\E2EArtifact;
use Tests\Support\Factory;

/*
 * The skeleton's whole promise, end to end through the router: a person signs
 * in, is told what they may do, reaches what their level allows and is refused
 * what it does not, signs out, and reaches nothing.
 *
 * Ends by writing coverage/e2e/sign-in-journey.json: the facts observed, named
 * by role, every check made, and a SHA-256 of both -- the same digest on every
 * run of an unchanged codebase.
 */

it('walks a person from signing in to signing out, and records what it saw', function (): void {
    $artifact = new E2EArtifact('sign-in-journey');

    foreach ([Role::Owner, Role::Administrator, Role::User] as $role) {
        $id = Factory::createUser($role);
        [$email] = Factory::credentialsFor($id);

        $login = $this->post('/api/auth/login', ['email' => $email, 'password' => Factory::PASSWORD]);
        $permissions = $this->json($login)['data']['permissions'];
        $hello = $this->get('/api/hello');
        $settingsRead = $this->get('/api/settings')->getStatusCode();
        $settingsWrite = $this->put('/api/settings', ['values' => ['page_size' => '30']])->getStatusCode();
        $logout = $this->post('/api/auth/logout')->getStatusCode();
        $afterLogout = $this->get('/api/hello')->getStatusCode();

        $artifact
            ->fact("{$role->value}: permissions", $permissions)
            ->fact("{$role->value}: hello", $this->json($hello)['data']['message'])
            ->fact("{$role->value}: settings read", $settingsRead)
            ->fact("{$role->value}: settings write", $settingsWrite)
            ->check("{$role->value} signs in", $login->getStatusCode() === 200)
            ->check("{$role->value} is greeted", $hello->getStatusCode() === 200)
            ->check("{$role->value} reads settings only with settings-view", ($settingsRead === 200) === in_array('settings-view', $permissions, true))
            ->check("{$role->value} writes settings only with settings-edit", ($settingsWrite === 200) === in_array('settings-edit', $permissions, true))
            ->check("{$role->value} signs out", $logout === 200)
            ->check("{$role->value} reaches nothing after signing out", $afterLogout === 401);
    }

    $path = $artifact->write();
    $written = json_decode((string) file_get_contents($path), true);

    expect($written['passed'])->toBeTrue()
        ->and($written['sha256'])->toBe($artifact->digest())
        ->and($written['facts']['owner: settings write'])->toBe(200)
        ->and($written['facts']['user: settings read'])->toBe(403);
});
