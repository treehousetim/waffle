<?php

declare(strict_types=1);

use App\Domain\Auth\AccountDisabled;
use App\Domain\Auth\AccountNotVerified;
use App\Domain\Auth\InvalidCredentials;
use App\Domain\Auth\Role;
use App\Domain\Auth\TooManyAttempts;
use App\Domain\Ports\IdentityPort;
use App\Libraries\Auth\DelightIdentity;
use Delight\Auth\Auth;
use Delight\Db\PdoDatabase;
use Tests\Support\Factory;
use Tests\TestCase;

/**
 * The adapter is the only place Delight\Auth is referenced, so its translation
 * of that library's vocabulary into the application's is tested against the
 * real library rather than a double — Delight\Auth is final and cannot be
 * doubled anyway.
 */
beforeEach(function (): void {
    $this->identity = TestCase::container()->get(IdentityPort::class);
});

it('reports nobody when no one is signed in', function (): void {
    expect($this->identity->isAuthenticated())->toBeFalse()
        ->and($this->identity->id())->toBeNull()
        ->and($this->identity->email())->toBeNull()
        ->and($this->identity->username())->toBeNull()
        ->and($this->identity->role())->toBeNull();
});

it('reports the signed-in user after a successful login', function (): void {
    $id = Factory::createUser(Role::Owner, 'owner@example.test');

    $this->identity->login('owner@example.test', Factory::PASSWORD);

    expect($this->identity->isAuthenticated())->toBeTrue()
        ->and($this->identity->id())->toBe($id)
        ->and($this->identity->email())->toBe('owner@example.test')
        ->and($this->identity->username())->toBe('Test User')
        ->and($this->identity->role())->toBe(Role::Owner);
});

it('prefers the higher level when a user carries two', function (): void {
    $id = Factory::createUser(Role::Administrator, 'both@example.test');
    TestCase::pdo()
        ->prepare('UPDATE users SET roles_mask = ? WHERE id = ?')
        ->execute([Role::Owner->mask() | Role::Administrator->mask(), $id]);

    $this->identity->login('both@example.test', Factory::PASSWORD);

    expect($this->identity->role())->toBe(Role::Owner);
});

it('reports no username for an account that has none', function (): void {
    $id = Factory::createUser(Role::User, 'nameless@example.test');
    TestCase::pdo()->prepare('UPDATE users SET username = NULL WHERE id = ?')->execute([$id]);

    $this->identity->login('nameless@example.test', Factory::PASSWORD);

    expect($this->identity->username())->toBeNull();
});

it('clears the session on logout', function (): void {
    Factory::createUser(Role::Administrator, 'admin@example.test');
    $this->identity->login('admin@example.test', Factory::PASSWORD);

    $this->identity->logout();

    expect($this->identity->isAuthenticated())->toBeFalse()
        ->and($this->identity->role())->toBeNull();
});

it('translates a wrong password into InvalidCredentials', function (): void {
    Factory::createUser(null, 'someone@example.test');

    expect(fn () => $this->identity->login('someone@example.test', 'wrong'))
        ->toThrow(InvalidCredentials::class, 'Invalid email address or password.');
});

it('translates an unknown address into the same InvalidCredentials', function (): void {
    expect(fn () => $this->identity->login('nobody@example.test', 'wrong'))
        ->toThrow(InvalidCredentials::class, 'Invalid email address or password.');
});

it('translates an unverified address into AccountNotVerified', function (): void {
    $id = Factory::createUnverifiedUser();
    [$email] = Factory::credentialsFor($id);

    expect(fn () => $this->identity->login($email, Factory::PASSWORD))
        ->toThrow(AccountNotVerified::class, 'This account has not been verified.');
});

it('refuses an account that has been turned off, and leaves no session behind', function (): void {
    $id = Factory::createDisabledUser();
    [$email] = Factory::credentialsFor($id);

    expect(fn () => $this->identity->login($email, Factory::PASSWORD))->toThrow(AccountDisabled::class)
        ->and($this->identity->isAuthenticated())->toBeFalse();
});

it('translates Delight rate limiting into TooManyAttempts', function (): void {
    // The container's Auth has throttling switched off for the suite, so this
    // builds a throttling one against the same connection and hammers it.
    $throttled = new DelightIdentity(new Auth(PdoDatabase::fromPdo(TestCase::pdo()), '203.0.113.7', null, true));

    $thrown = null;

    for ($attempt = 0; $attempt < 200 && $thrown === null; ++$attempt) {
        try {
            $throttled->login('nobody@example.test', 'wrong');
        } catch (TooManyAttempts $e) {
            $thrown = $e;
        } catch (InvalidCredentials) {
            // expected until the bucket empties
        }
    }

    expect($thrown)->toBeInstanceOf(TooManyAttempts::class)
        ->and($thrown->getMessage())->toBe('Too many attempts. Try again shortly.');
});
