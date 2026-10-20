<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use Tests\Support\Factory;

/*
 * Invitations through the router, with the real Delight\Auth
 * (docs/invitations.md). AccessGridTest checks 401 and 403 for the
 * organisers' routes; these check what each route does. The numbers are the
 * failure list's.
 */

it('lets an owner invite an administrator, who joins and is signed in at that level (1, 10)', function (): void {
    $this->asOwner();

    $made = $this->post('/api/invitations', ['email' => 'co-organiser@example.test', 'level' => 'administrator']);
    $token = $this->json($made)['data']['token'];

    $this->post('/api/auth/logout');
    $opened = $this->get("/api/invite/{$token}");
    $accepted = $this->post("/api/invite/{$token}/accept", ['name' => 'Co Organiser', 'password' => 'correct horse battery']);
    $me = $this->json($this->get('/api/auth/me'))['data'];

    expect($made->getStatusCode())->toBe(201)
        ->and($this->json($made)['data']['invitation'])->toMatchArray(['email' => 'co-organiser@example.test', 'level' => 'administrator', 'status' => 'pending'])
        ->and($this->json($opened)['data'])->toBe(['email' => 'co-organiser@example.test', 'level_label' => 'Administrator'])
        ->and($accepted->getStatusCode())->toBe(200)
        ->and($me)->toMatchArray(['email' => 'co-organiser@example.test', 'username' => 'Co Organiser', 'role' => 'administrator'])
        ->and($this->post("/api/invite/{$token}/accept", ['name' => 'Again', 'password' => 'correct horse battery'])->getStatusCode())->toBe(404);
});

it('signs out whoever the browser belonged to before signing the new person in (10)', function (): void {
    $this->asOwner();
    $token = $this->json($this->post('/api/invitations', ['email' => 'next@example.test', 'level' => 'user']))['data']['token'];

    $this->post("/api/invite/{$token}/accept", ['name' => 'Next Person', 'password' => 'correct horse battery']);

    expect($this->json($this->get('/api/auth/me'))['data'])->toMatchArray(['email' => 'next@example.test', 'role' => 'user']);
});

it('lets an administrator invite users only, and lists invitations with the levels one may give (4)', function (): void {
    $this->asAdministrator();

    $refused = $this->post('/api/invitations', ['email' => 'x@example.test', 'level' => 'administrator']);
    $this->post('/api/invitations', ['email' => 'member@example.test', 'level' => 'user']);
    $list = $this->json($this->get('/api/invitations'))['data'];

    expect($refused->getStatusCode())->toBe(422)
        ->and($this->json($refused)['error'])->toBe('You cannot invite someone at that level.')
        ->and($list['levels'])->toBe([['value' => 'user', 'label' => 'User']])
        ->and($list['invitations'][0])->toMatchArray(['email' => 'member@example.test', 'status' => 'pending'])
        ->and($list['invitations'][0])->not->toHaveKey('token');
});

it('revokes an invitation, after which its link is not valid (2, 13)', function (): void {
    $this->asOwner();
    $made = $this->json($this->post('/api/invitations', ['email' => 'gone@example.test', 'level' => 'user']))['data'];
    $id = $made['invitation']['id'];

    $revoked = $this->post("/api/invitations/{$id}/revoke");
    $again = $this->post("/api/invitations/{$id}/revoke");

    expect($this->json($revoked)['data']['invitation']['status'])->toBe('revoked')
        ->and($again->getStatusCode())->toBe(409)
        ->and($this->post('/api/invitations/999999/revoke')->getStatusCode())->toBe(404)
        ->and($this->post('/api/invitations/abc/revoke')->getStatusCode())->toBe(404)
        ->and($this->get("/api/invite/{$made['token']}")->getStatusCode())->toBe(404);
});

it('answers every unusable link the same way (8)', function (): void {
    foreach (['short', str_repeat('A', 43)] as $token) {
        $response = $this->get("/api/invite/{$token}");

        expect($response->getStatusCode())->toBe(404)
            ->and($this->json($response)['error'])->toBe('This invitation link is not valid.');
    }

    expect($this->post('/api/invite/short/accept', ['name' => 'A', 'password' => 'correct horse battery'])->getStatusCode())->toBe(404);
});

it('refuses a weak password, or an address that has an account (5, 9)', function (): void {
    $this->asOwner();
    Factory::createUser(Role::User, 'exists@example.test');
    $token = $this->json($this->post('/api/invitations', ['email' => 'weak@example.test', 'level' => 'user']))['data']['token'];

    $weak = $this->post("/api/invite/{$token}/accept", ['name' => 'Weak', 'password' => 'short']);
    $taken = $this->post('/api/invitations', ['email' => 'exists@example.test', 'level' => 'user']);

    expect($weak->getStatusCode())->toBe(422)
        ->and($this->json($weak)['error'])->toBe('Choose a password of at least 12 characters.')
        ->and($taken->getStatusCode())->toBe(422)
        ->and($this->json($taken)['error'])->toBe('That address already has an account.');
});
