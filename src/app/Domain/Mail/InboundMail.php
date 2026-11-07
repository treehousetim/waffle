<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\Job;

/** A message sent to the domain, waiting to be fetched and filed (docs/mail.md): only Resend's id for it. */
final readonly class InboundMail implements Job
{
    private function __construct(public string $emailId)
    {
    }

    public static function of(string $emailId): self
    {
        return new self($emailId);
    }

    public static function type(): string
    {
        return 'inbound-mail';
    }

    public function payload(): array
    {
        return ['email_id' => $this->emailId];
    }

    public static function fromPayload(array $payload): static
    {
        $id = $payload['email_id'] ?? null;

        if (!is_string($id) || !self::isId($id)) {
            throw InvalidJobPayload::because(self::type(), 'has no email id');
        }

        return new self($id);
    }

    /** Resend's ids are UUIDs; anything else is not put in a URL. */
    public static function isId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) === 1;
    }

    public function merge(Job $newer): Job
    {
        return $newer;
    }
}
