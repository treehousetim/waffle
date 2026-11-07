<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use RuntimeException;

/** A reset link that cannot be used, whatever the reason: one answer for all (docs/password-reset.md, 2.). */
final class ResetNotFound extends RuntimeException
{
    public static function link(): self
    {
        return new self('This link is not valid. Ask for a new one.');
    }
}
