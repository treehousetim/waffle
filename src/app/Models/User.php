<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auth\Role;
use DateTimeImmutable;
use DateTimeZone;

/** One row of Delight\Auth's `users` table, as the application reads it. */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public ?string $username,
        public ?Role $role,
        public DateTimeImmutable $registeredAt,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            $row['username'] === null ? null : (string) $row['username'],
            Role::fromMask((int) $row['roles_mask']),
            // Delight stores a Unix timestamp, which is UTC by definition.
            (new DateTimeImmutable('@' . (int) $row['registered']))->setTimezone(new DateTimeZone('UTC')),
        );
    }
}
