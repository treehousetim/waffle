<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Reads of Delight\Auth's `users` table.
 *
 * Writes (registration, password changes, role changes) go through
 * Delight\Auth itself, via IdentityPort, so its hashing and audit-log
 * behaviour is never bypassed.
 *
 * Delight's tables are the one documented exception to the column-prefix rule:
 * they are the library's schema, not ours.
 */
final readonly class UserRepository
{
    private const string COLUMNS = 'id, email, username, roles_mask, registered';

    /** Delight's `status` value for a normal account (Delight\Auth\Status::NORMAL). */
    private const int STATUS_NORMAL = 0;

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?');
        $statement->execute([$id]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    /**
     * Whether the account may be used. A missing account is not enabled.
     *
     * Any status but normal counts as turned off: Delight's own vocabulary has
     * several (archived, banned, locked, suspended...), and none of them
     * should be able to keep a session alive.
     */
    public function isEnabled(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT status FROM users WHERE id = ?');
        $statement->execute([$id]);

        $status = $statement->fetchColumn();

        return $status !== false && (int) $status === self::STATUS_NORMAL;
    }
}
