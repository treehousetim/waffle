<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domain\Auth\AuthenticationFailure;
use App\Domain\Auth\Role;
use App\Domain\Ports\IdentityPort;

/**
 * An in-memory IdentityPort. Lets middleware and controller unit tests
 * describe who is calling without a database, a session, or Delight\Auth.
 */
final class FakeIdentity implements IdentityPort
{
    public bool $loggedOut = false;

    /** @var list<array{0:string,1:string}> */
    public array $loginAttempts = [];

    public function __construct(
        private ?Role $role = null,
        private bool $authenticated = false,
        private ?int $id = null,
        private ?string $email = null,
        private ?string $username = null,
        private ?AuthenticationFailure $loginFailure = null,
    ) {
    }

    public static function anonymous(): self
    {
        return new self();
    }

    public static function signedIn(?Role $role = null, int $id = 1, string $email = 'someone@example.test'): self
    {
        return new self($role, true, $id, $email, 'Test User');
    }

    public static function failingLoginWith(AuthenticationFailure $failure): self
    {
        return new self(loginFailure: $failure);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function role(): ?Role
    {
        return $this->role;
    }

    public function login(string $email, string $password): void
    {
        $this->loginAttempts[] = [$email, $password];

        if ($this->loginFailure !== null) {
            throw $this->loginFailure;
        }

        $this->authenticated = true;
        $this->email = $email;
    }

    public function logout(): void
    {
        $this->loggedOut = true;
        $this->authenticated = false;
    }

    /** @var array<int,?Role> the levels setRole() was asked to give, by account */
    public array $rolesSet = [];

    public function setRole(int $userId, ?Role $role): void
    {
        $this->rolesSet[$userId] = $role;
    }

    /** @var array<string,array{password:string,name:string}> accounts createAccount() made, by address */
    public array $accountsCreated = [];

    /** @var list<string> addresses createAccount() finds already taken */
    public array $existingAccounts = [];

    public function createAccount(string $email, string $password, string $name): int
    {
        if (in_array($email, $this->existingAccounts, true)) {
            throw new \App\Domain\Auth\AccountExists('An account with that email address already exists.');
        }

        $this->accountsCreated[$email] = ['password' => $password, 'name' => $name];

        // A real row with no level, so whatever refers to the account can.
        return \Tests\Support\Factory::createUser(null, $email);
    }
}
