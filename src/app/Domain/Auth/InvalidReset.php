<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use InvalidArgumentException;

/** A password reset request or a new password that cannot be used (docs/password-reset.md). */
final class InvalidReset extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
