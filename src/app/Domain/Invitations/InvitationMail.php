<?php

declare(strict_types=1);

namespace App\Domain\Invitations;

use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\Job;

/**
 * An invitation waiting to be emailed (docs/invitations.md). Only its id: the
 * link opens an account, so it is issued by the worker as the email is sent,
 * and never kept in the job (docs/mail.md, 4.).
 */
final readonly class InvitationMail implements Job
{
    private function __construct(public int $invitationId)
    {
    }

    public static function of(int $invitationId): self
    {
        return new self($invitationId);
    }

    public static function type(): string
    {
        return 'invitation-mail';
    }

    public function payload(): array
    {
        return ['invitation_id' => $this->invitationId];
    }

    public static function fromPayload(array $payload): static
    {
        $id = $payload['invitation_id'] ?? null;

        if (!is_int($id) || $id < 1) {
            throw InvalidJobPayload::because(self::type(), 'has no invitation id');
        }

        return new self($id);
    }

    public function merge(Job $newer): Job
    {
        return $newer;
    }
}
