<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use InvalidArgumentException;

/** A message that cannot be sent as it is (docs/mail.md, 5.). */
final class InvalidMessage extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
