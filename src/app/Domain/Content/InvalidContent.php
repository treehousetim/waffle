<?php

declare(strict_types=1);

namespace App\Domain\Content;

use InvalidArgumentException;

/** Content a record will not take, or a record not fit to publish; the message is for staff. */
final class InvalidContent extends InvalidArgumentException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
