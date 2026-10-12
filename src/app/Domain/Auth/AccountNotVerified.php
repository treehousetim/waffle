<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/** The credentials were right, but the address has never been confirmed. */
final class AccountNotVerified extends AuthenticationFailure
{
}
