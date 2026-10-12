<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\Role;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * Fixture helpers. Everything inserts through the shared PDO, so it is inside
 * the transaction the suite rolls back after each test.
 *
 * Add a create<Thing>() here (or in a slice's own factory class beside it) for
 * every table a test needs rows in, rather than writing raw SQL in test bodies.
 */
final class Factory
{
    public const string PASSWORD = 'correct-horse-battery-staple';

    /**
     * @var array<int,array{0:string,1:string}> user id => [email, password]
     */
    private static array $credentials = [];

    /** One bcrypt for the whole run. See passwordHash(). */
    private static ?string $passwordHash = null;

    public static function pdo(): PDO
    {
        return TestCase::pdo();
    }

    /**
     * A test user, inserted directly rather than through Delight\Auth.
     *
     * Delight's own createUser() hashes the password, and on PHP 8.5
     * `PASSWORD_DEFAULT` is bcrypt at **cost 12** — around 170ms per call.
     * Hundreds of tests create a user, so that alone would be most of the
     * suite's runtime.
     *
     * Every test user shares one password, so the hash only has to be computed
     * once for the whole run. Nothing else changes: the same algorithm at the
     * same cost, which matters because Delight re-hashes on login when
     * `needsRehash()` says the cost has moved — a cheaper hash would be
     * silently upgraded on first use and cost *more* than it saved. The login
     * path in actingAs() still runs through the real Delight\Auth, so the
     * middleware is exercised exactly as in production.
     *
     * The five columns are the five Delight's own insert writes; everything
     * else in its schema has a database default. `verified` must be 1 or
     * login refuses.
     */
    public static function createUser(?Role $role = null, ?string $email = null): int
    {
        $email ??= 'user-' . bin2hex(random_bytes(6)) . '@example.test';

        $statement = self::pdo()->prepare(
            'INSERT INTO users (email, password, username, verified, roles_mask, registered)'
            . ' VALUES (?, ?, ?, 1, ?, ?)'
        );
        $statement->execute([
            $email,
            self::passwordHash(),
            'Test User',
            $role?->mask() ?? 0,
            time(),
        ]);

        $id = (int) self::pdo()->lastInsertId();

        self::$credentials[$id] = [$email, self::PASSWORD];

        return $id;
    }

    /**
     * A user Delight\Auth will refuse to sign in because their address has
     * never been confirmed.
     */
    public static function createUnverifiedUser(): int
    {
        $id = self::createUser();

        self::pdo()->prepare('UPDATE users SET verified = 0 WHERE id = ?')->execute([$id]);

        return $id;
    }

    /**
     * A user whose account has been turned off (Delight's status column; any
     * value but 0 means not normal). 1 archived, 2 banned, 3 locked, 4 pending
     * review, 5 suspended -- Delight\Auth\Status.
     */
    public static function createDisabledUser(?Role $role = Role::User, int $status = 5): int
    {
        $id = self::createUser($role);

        self::disable($id, $status);

        return $id;
    }

    public static function disable(int $userId, int $status = 5): void
    {
        self::pdo()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $userId]);
    }

    /**
     * @return array{0:string,1:string} [email, password]
     */
    public static function credentialsFor(int $userId): array
    {
        if (!isset(self::$credentials[$userId])) {
            throw new RuntimeException(
                "No credentials recorded for user {$userId}. Create users through Factory::createUser()."
            );
        }

        return self::$credentials[$userId];
    }

    /**
     * Inserts a row and returns its id.
     *
     * Slice factories build on this so a test can seed another slice's table
     * without either slice owning the other's factory file.
     *
     * @param array<string,scalar|null> $row
     */
    public static function insert(string $table, array $row): int
    {
        $columns = array_keys($row);

        $statement = self::pdo()->prepare(sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        ));
        $statement->execute(array_values($row));

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * The shared password's hash, computed once per process.
     *
     * PASSWORD_DEFAULT rather than a pinned cost, so it always matches what
     * Delight would have produced and never trips its rehash-on-login.
     */
    private static function passwordHash(): string
    {
        return self::$passwordHash ??= password_hash(self::PASSWORD, PASSWORD_DEFAULT);
    }
}
