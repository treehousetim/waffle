<?php

declare(strict_types=1);

namespace App\Domain\Ports;

use App\Domain\Auth\AccountDisabled;
use App\Domain\Auth\AccountNotVerified;
use App\Domain\Auth\InvalidCredentials;
use App\Domain\Auth\Role;
use App\Domain\Auth\TooManyAttempts;

/**
 * Who is making this request, and how they sign in and out.
 *
 * Delight\Auth is declared final, so nothing above this line may depend on it
 * directly or middleware and controllers become untestable. The adapter lives
 * in Libraries/Auth/DelightIdentity and is the only place that knows the
 * library exists.
 *
 * Grow this port as the app needs more (creating users, changing passwords,
 * resetting another user's password): add the method here, implement it in
 * DelightIdentity, translate Delight's exceptions into Domain/Auth ones, and
 * add it to tests/Support/Fakes/FakeIdentity.
 */
interface IdentityPort
{
    public function isAuthenticated(): bool;

    public function id(): ?int;

    public function email(): ?string;

    public function username(): ?string;

    /**
     * The highest-privilege role this identity carries, or null for none.
     */
    public function role(): ?Role;

    /**
     * @throws InvalidCredentials  the email or the password was wrong, or the
     *                             attempt was cancelled
     * @throws AccountNotVerified  the address has never been confirmed
     * @throws AccountDisabled     the account has been turned off
     * @throws TooManyAttempts     the caller is being rate limited
     */
    public function login(string $email, string $password): void;

    public function logout(): void;

    /**
     * Gives the account exactly this level, or none (an owner promoting an
     * administrator; a membership whose level follows its status). Takes
     * effect on the account's next request: the session is resynchronised
     * every time.
     *
     * @throws \InvalidArgumentException when there is no such account
     */
    public function setRole(int $userId, ?Role $role): void;
}
