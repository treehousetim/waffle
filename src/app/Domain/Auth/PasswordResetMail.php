<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\Job;

/**
 * A password reset link waiting to be emailed (docs/password-reset.md). Only
 * the request's id: the worker issues the link as it sends (3.).
 */
final readonly class PasswordResetMail implements Job
{
    private function __construct(public int $resetId)
    {
    }

    public static function of(int $resetId): self
    {
        return new self($resetId);
    }

    public static function type(): string
    {
        return 'password-reset-mail';
    }

    public function payload(): array
    {
        return ['reset_id' => $this->resetId];
    }

    public static function fromPayload(array $payload): static
    {
        $id = $payload['reset_id'] ?? null;

        if (!is_int($id) || $id < 1) {
            throw InvalidJobPayload::because(self::type(), 'has no reset id');
        }

        return new self($id);
    }

    public function merge(Job $newer): Job
    {
        return $newer;
    }
}
