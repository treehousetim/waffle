<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use Tests\Support\Factory;

it('greets a signed-in user by address', function (): void {
    $id = Factory::createUser(Role::User);
    [$email] = Factory::credentialsFor($id);
    $this->actingAs($id);

    $response = $this->get('/api/hello');

    expect($response->getStatusCode())->toBe(200)
        ->and($this->json($response))->toBe([
            'success' => true,
            'data' => ['message' => 'Hello from Waffle.', 'email' => $email],
        ]);
});

it('greets every level', function (Role $role): void {
    $this->actingAs(Factory::createUser($role));

    expect($this->get('/api/hello')->getStatusCode())->toBe(200);
})->with([
    'owner' => [Role::Owner],
    'administrator' => [Role::Administrator],
    'user' => [Role::User],
]);

it('refuses an anonymous request with 401', function (): void {
    $response = $this->get('/api/hello');

    expect($response->getStatusCode())->toBe(401)
        ->and($this->json($response))->toBe(['success' => false, 'error' => 'Authentication required.']);
});

it('refuses an account with no level with 403', function (): void {
    $this->asRoleless();

    $response = $this->get('/api/hello');

    expect($response->getStatusCode())->toBe(403)
        ->and($this->json($response))->toBe(['success' => false, 'error' => 'Your access level does not include this.']);
});
