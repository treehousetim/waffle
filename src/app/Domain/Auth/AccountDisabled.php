<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * An owner or administrator has turned this account off.
 *
 * Its own type rather than InvalidCredentials: the password was right, and
 * saying "wrong password" to somebody whose access was revoked sends them to
 * reset it, which will not help. 403, not 401.
 */
final class AccountDisabled extends AuthenticationFailure
{
    public static function byOwner(): self
    {
        return new self('This account has been turned off. Ask an owner to turn it back on.');
    }
}
