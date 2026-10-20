<?php

declare(strict_types=1);

namespace App\Domain\Invitations;

use App\Domain\Status\HasTransitions;

/**
 * Where an invitation stands (docs/invitations.md). Also the `types` group
 * `invitation_status`; VocabularyTest keeps the two in step. Expiry is not a
 * status: a pending invitation past its time simply opens nothing.
 *
 *   pending → accepted | revoked
 */
enum InvitationStatus: string
{
    use HasTransitions;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';

    public static function machineName(): string
    {
        return 'invitation';
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Accepted, self::Revoked],
            self::Accepted, self::Revoked => [],
        };
    }
}
