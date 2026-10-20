<?php

declare(strict_types=1);

namespace App\Domain\Invitations;

use InvalidArgumentException;

/** An invitation, or an acceptance, the rules refuse; the message is for the person. */
final class InvalidInvitation extends InvalidArgumentException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
