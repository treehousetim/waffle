<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/** The caller is being rate limited after too many failed attempts. */
final class TooManyAttempts extends AuthenticationFailure
{
}
