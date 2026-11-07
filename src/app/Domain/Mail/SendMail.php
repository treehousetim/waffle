<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\Job;

/**
 * An email that carries no secret, waiting to be sent (docs/mail.md): the
 * whole message, and a key made when it was queued, which the provider is
 * given so a job run twice sends once (3.). Never coalesced: every email is
 * its own.
 */
final readonly class SendMail implements Job
{
    private function __construct(public Message $message, public string $key)
    {
    }

    public static function of(Message $message): self
    {
        return new self($message, bin2hex(random_bytes(16)));
    }

    public static function type(): string
    {
        return 'send-mail';
    }

    public function payload(): array
    {
        return ['key' => $this->key, 'message' => $this->message->toArray()];
    }

    public static function fromPayload(array $payload): static
    {
        $key = $payload['key'] ?? null;
        $message = $payload['message'] ?? null;

        if (!is_string($key) || preg_match('/^[0-9a-f]{32}$/', $key) !== 1 || !is_array($message)) {
            throw InvalidJobPayload::because(self::type(), 'has no key or no message');
        }

        try {
            return new self(Message::fromArray($message), $key);
        } catch (InvalidMessage $e) {
            throw InvalidJobPayload::because(self::type(), $e->getMessage());
        }
    }

    public function merge(Job $newer): Job
    {
        return $newer;
    }
}
