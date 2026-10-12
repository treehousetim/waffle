<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use RuntimeException;

/**
 * A sign-in attempt that did not produce a session.
 *
 * Subclasses map one-to-one onto the responses AuthController returns, so the
 * controller never has to know which library raised the underlying error.
 */
abstract class AuthenticationFailure extends RuntimeException
{
}
