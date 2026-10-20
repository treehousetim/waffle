<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Domain\Auth\AccountDisabled;
use App\Domain\Auth\AccountExists;
use App\Domain\Auth\AccountNotVerified;
use App\Domain\Auth\InvalidCredentials;
use App\Domain\Auth\Role;
use App\Domain\Auth\TooManyAttempts;
use App\Domain\Ports\IdentityPort;
use Delight\Auth\AttemptCancelledException;
use Delight\Auth\Auth;
use Delight\Auth\EmailNotVerifiedException;
use Delight\Auth\InvalidEmailException;
use Delight\Auth\InvalidPasswordException;
use Delight\Auth\TooManyRequestsException;
use Delight\Auth\UnknownIdException;
use Delight\Auth\UserAlreadyExistsException;
use InvalidArgumentException;

/**
 * The only class in the application that knows Delight\Auth exists (apart from
 * the initial-owner migration).
 *
 * Its whole job is to answer the questions IdentityPort asks and to translate
 * Delight's exception vocabulary into the application's own.
 */
final readonly class DelightIdentity implements IdentityPort
{
    public function __construct(private Auth $auth)
    {
    }

    public function isAuthenticated(): bool
    {
        return $this->auth->isLoggedIn();
    }

    public function id(): ?int
    {
        $id = $this->auth->getUserId();

        return $id === null ? null : (int) $id;
    }

    public function email(): ?string
    {
        $email = $this->auth->getEmail();

        return $email === null ? null : (string) $email;
    }

    public function username(): ?string
    {
        $username = $this->auth->getUsername();

        return $username === null ? null : (string) $username;
    }

    public function role(): ?Role
    {
        // Delight\Auth::getRoles() returns its own descriptive names and does
        // not expose the raw mask, so the cases are probed in declaration
        // order — most privileged first.
        foreach (Role::cases() as $role) {
            if ($this->auth->hasRole($role->mask())) {
                return $role;
            }
        }

        return null;
    }

    public function login(string $email, string $password): void
    {
        /*
         * A fresh sign-in starts from nothing: whoever this session belonged
         * to is signed out first. Delight leaves an existing login untouched
         * when a new one fails, so without this a failed attempt in a
         * signed-in browser would leave the previous person signed in while
         * the form said the attempt was refused.
         */
        $this->logout();

        try {
            $this->auth->login($email, $password);
        } catch (InvalidEmailException | InvalidPasswordException | AttemptCancelledException $e) {
            throw new InvalidCredentials('Invalid email address or password.', 0, $e);
        } catch (EmailNotVerifiedException $e) {
            throw new AccountNotVerified('This account has not been verified.', 0, $e);
        } catch (TooManyRequestsException $e) {
            throw new TooManyAttempts('Too many attempts. Try again shortly.', 0, $e);
        }

        /*
         * Delight reads `status` and does not act on it.
         *
         * It fetches the column, exposes isNormal() and friends, and leaves the
         * policy to the application -- so without this an account that had
         * been turned off would go on signing in perfectly happily. Anything
         * but normal is refused, matching AuthMiddleware's rule (status 0):
         * isSuspended() alone would let a banned or locked account through.
         * The session has already been created by this point, so it is torn
         * down first.
         */
        if (!$this->auth->isNormal()) {
            $this->logout();

            throw AccountDisabled::byOwner();
        }
    }

    public function logout(): void
    {
        $this->auth->logOut();
    }

    public function setRole(int $userId, ?Role $role): void
    {
        try {
            foreach (Role::cases() as $level) {
                if ($level === $role) {
                    $this->auth->admin()->addRoleForUserById($userId, $level->mask());
                } else {
                    $this->auth->admin()->removeRoleForUserById($userId, $level->mask());
                }
            }
        } catch (UnknownIdException) {
            throw new InvalidArgumentException("There is no account {$userId}.");
        }
    }

    public function createAccount(string $email, string $password, string $name): int
    {
        try {
            return $this->auth->admin()->createUser($email, $password, $name);
        } catch (UserAlreadyExistsException $e) {
            throw new AccountExists('An account with that email address already exists.', 0, $e);
        } catch (InvalidEmailException | InvalidPasswordException $e) {
            throw new InvalidArgumentException('That email address or password cannot be used.', 0, $e);
        }
    }
}
