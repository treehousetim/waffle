<?php

declare(strict_types=1);

namespace App\Models;

/** One password reset request (docs/password-reset.md). Times are UTC, `Y-m-d H:i:s`. */
final readonly class PasswordReset
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $requestedAt,
        public string $expiresAt,
        public ?string $closedAt,
    ) {
    }

    /** Whether its link may still be used at this moment (2.). */
    public function opensAt(string $now): bool
    {
        return $this->closedAt === null && $now < $this->expiresAt;
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['password_resets_id'],
            (int) $row['password_resets_user_id'],
            (string) $row['password_resets_requested_at'],
            (string) $row['password_resets_expires_at'],
            $row['password_resets_closed_at'] === null ? null : (string) $row['password_resets_closed_at'],
        );
    }
}
