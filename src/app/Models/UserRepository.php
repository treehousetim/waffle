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
     * The id of the enabled, verified account with this address, if there is
     * one (docs/password-reset.md, 5.).
     */
    public function usableIdByEmail(string $email): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE email = ? AND status = ? AND verified = 1');
        $statement->execute([$email, self::STATUS_NORMAL]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * The addresses of every enabled, verified account holding any of these
     * levels, oldest first: who to tell about something only they may see
     * (docs/mail.md, 9.).
     *
     * @param list<\App\Domain\Auth\Role> $roles
     *
     * @return list<string>
     */
    public function addressesWithAnyRole(array $roles): array
    {
        $mask = array_reduce($roles, static fn (int $carry, \App\Domain\Auth\Role $role): int => $carry | $role->mask(), 0);
        $statement = $this->pdo->prepare('SELECT email FROM users WHERE status = ? AND verified = 1 AND (roles_mask & ?) <> 0 ORDER BY id');
        $statement->execute([self::STATUS_NORMAL, $mask]);

        /** @var list<string> $addresses */
        $addresses = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $addresses;
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
