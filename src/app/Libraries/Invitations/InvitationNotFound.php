<?php

declare(strict_types=1);

namespace App\Libraries\Invitations;

use RuntimeException;

/**
 * No invitation to act on. For a link, every reason (wrong, expired, revoked,
 * used) is this one message, so the answer helps nobody guess (docs/invitations.md, 8.).
 */
final class InvitationNotFound extends RuntimeException
{
    public static function link(): self
    {
        return new self('This invitation link is not valid.');
    }

    public static function id(): self
    {
        return new self('There is no invitation with that id.');
    }
}
