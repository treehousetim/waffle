<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Models\UserRepository;
use Tests\Support\Factory;
use Tests\TestCase;

beforeEach(function (): void {
    $this->users = new UserRepository(TestCase::pdo());
});

it('finds a user by id, with their level and when they registered', function (): void {
    $id = Factory::createUser(Role::Administrator, 'found@example.test');

    $user = $this->users->findById($id);

    expect($user->id)->toBe($id)
        ->and($user->email)->toBe('found@example.test')
        ->and($user->username)->toBe('Test User')
        ->and($user->role)->toBe(Role::Administrator)
        ->and($user->registeredAt->getTimezone()->getName())->toBe('UTC')
        ->and(abs($user->registeredAt->getTimestamp() - time()))->toBeLessThan(60);
});

it('reads a missing username and a missing level as null', function (): void {
    $id = Factory::createUser(null);
    TestCase::pdo()->prepare('UPDATE users SET username = NULL WHERE id = ?')->execute([$id]);

    $user = $this->users->findById($id);

    expect($user->username)->toBeNull()
        ->and($user->role)->toBeNull();
});

it('finds nobody for an id that does not exist', function (): void {
    expect($this->users->findById(999_999_999))->toBeNull();
});

it('says whether an account may be used', function (): void {
    $enabled = Factory::createUser(Role::User);
    $disabled = Factory::createDisabledUser();

    expect($this->users->isEnabled($enabled))->toBeTrue()
        ->and($this->users->isEnabled($disabled))->toBeFalse()
        // A missing account is not enabled: nothing to keep a session alive for.
        ->and($this->users->isEnabled(999_999_999))->toBeFalse();
});
