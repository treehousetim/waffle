<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use Tests\Support\Factory;

// -------------------------------------------------------------------- login

it('signs in a user with valid credentials and says who they are', function (): void {
    $id = Factory::createUser(Role::Owner);
    [$email] = Factory::credentialsFor($id);

    $response = $this->post('/api/auth/login', ['email' => $email, 'password' => Factory::PASSWORD]);
    $body = $this->json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['success'])->toBeTrue()
        ->and($body['data'])->toBe([
            'id' => $id,
            'email' => $email,
            'username' => 'Test User',
            'role' => 'owner',
            'role_label' => 'Owner',
            'permissions' => ['home', 'settings-view', 'settings-edit'],
        ]);
});

it('reports each level and only the permissions it holds', function (Role $role, array $permissions): void {
    $id = Factory::createUser($role);
    [$email] = Factory::credentialsFor($id);

    $body = $this->json($this->post('/api/auth/login', ['email' => $email, 'password' => Factory::PASSWORD]));

    expect($body['data']['role'])->toBe($role->value)
        ->and($body['data']['permissions'])->toBe($permissions);
})->with([
    'administrator' => [Role::Administrator, ['home', 'settings-view']],
    'user' => [Role::User, ['home']],
]);

it('reports no level and no permissions for an account without one', function (): void {
    $id = Factory::createUser();
    [$email] = Factory::credentialsFor($id);

    $body = $this->json($this->post('/api/auth/login', ['email' => $email, 'password' => Factory::PASSWORD]));

    expect($body['data']['role'])->toBeNull()
        ->and($body['data']['role_label'])->toBeNull()
        ->and($body['data']['permissions'])->toBe([]);
});

it('rejects a wrong password without saying which field was wrong', function (): void {
    $id = Factory::createUser();
    [$email] = Factory::credentialsFor($id);

    $response = $this->post('/api/auth/login', ['email' => $email, 'password' => 'not-the-password']);

    expect($response->getStatusCode())->toBe(401)
        ->and($this->json($response))->toBe(['success' => false, 'error' => 'Invalid email address or password.']);
});

it('rejects an unknown email address with the same message as a wrong password', function (): void {
    $response = $this->post('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever']);

    expect($response->getStatusCode())->toBe(401)
        ->and($this->json($response)['error'])->toBe('Invalid email address or password.');
});

it('rejects an account whose email has never been verified', function (): void {
    $id = Factory::createUnverifiedUser();
    [$email] = Factory::credentialsFor($id);

    $response = $this->post('/api/auth/login', ['email' => $email, 'password' => Factory::PASSWORD]);

    expect($response->getStatusCode())->toBe(403)
        ->and($this->json($response)['error'])->toBe('This account has not been verified.');
});

it('rejects an account that has been turned off, and leaves nobody signed in', function (): void {
    $id = Factory::createDisabledUser();
    [$email] = Factory::credentialsFor($id);

    $response = $this->post('/api/auth/login', ['email' => $email, 'password' => Factory::PASSWORD]);

    expect($response->getStatusCode())->toBe(403)
        ->and($this->json($response)['error'])->toBe('This account has been turned off. Ask an owner to turn it back on.')
        ->and($this->get('/api/auth/me')->getStatusCode())->toBe(401);
});

it('requires both an email address and a password', function (array $body): void {
    $response = $this->post('/api/auth/login', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and($this->json($response)['error'])->toBe('Email address and password are required.');
})->with([
    'nothing' => [[]],
    'no email' => [['password' => 'something']],
    'no password' => [['email' => 'someone@example.test']],
    'blank email' => [['email' => '   ', 'password' => 'something']],
    'not a string' => [['email' => ['a@b.c'], 'password' => 'something']],
]);

it('signs the previous person out when a new sign-in fails', function (): void {
    $this->asOwner();

    $this->post('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);

    expect($this->get('/api/auth/me')->getStatusCode())->toBe(401);
});

// ------------------------------------------------------------------- logout

it('signs out, after which the session reaches nothing', function (): void {
    $this->asUser();

    $response = $this->post('/api/auth/logout');

    expect($response->getStatusCode())->toBe(200)
        ->and($this->json($response))->toBe(['success' => true, 'data' => null])
        ->and($this->get('/api/auth/me')->getStatusCode())->toBe(401);
});

it('answers a logout from somebody not signed in without complaint', function (): void {
    expect($this->post('/api/auth/logout')->getStatusCode())->toBe(200);
});

// ----------------------------------------------------------------------- me

it('says who is signed in', function (): void {
    $id = Factory::createUser(Role::Administrator);
    $this->actingAs($id);

    $body = $this->json($this->get('/api/auth/me'));

    expect($body['success'])->toBeTrue()
        ->and($body['data']['id'])->toBe($id)
        ->and($body['data']['role'])->toBe('administrator')
        ->and($body['data']['role_label'])->toBe('Administrator');
});

it('answers 401 to me when nobody is signed in', function (): void {
    $response = $this->get('/api/auth/me');

    expect($response->getStatusCode())->toBe(401)
        ->and($this->json($response))->toBe(['success' => false, 'error' => 'Authentication required.']);
});

it('ends the session of an account turned off while it was signed in', function (): void {
    $id = Factory::createUser(Role::Owner);
    $this->actingAs($id);

    Factory::disable($id);
    $response = $this->get('/api/auth/me');

    expect($response->getStatusCode())->toBe(403)
        ->and($this->json($response)['error'])->toBe('This account has been turned off.')
        ->and($this->get('/api/auth/me')->getStatusCode())->toBe(401);
});
