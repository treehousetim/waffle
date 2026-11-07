<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** The `password_resets` table (docs/password-reset.md). UTC throughout. */
final readonly class PasswordResetRepository
{
    private const string SELECT = 'SELECT password_resets_id, password_resets_user_id, password_resets_requested_at,'
        . ' password_resets_expires_at, password_resets_closed_at FROM password_resets';

    public function __construct(private PDO $pdo)
    {
    }

    public static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function insert(int $userId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): int
    {
        $this->pdo->prepare('INSERT INTO password_resets (password_resets_user_id, password_resets_requested_at, password_resets_expires_at) VALUES (?, ?, ?)')
            ->execute([$userId, self::time($now), self::time($expiresAt)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id, bool $forUpdate = false): ?PasswordReset
    {
        return $this->one(' WHERE password_resets_id = ?', [$id], $forUpdate);
    }

    public function findByTokenHash(string $hash, bool $forUpdate = false): ?PasswordReset
    {
        return $this->one(' WHERE password_resets_token_hash = ?', [$hash], $forUpdate);
    }

    /** How many resets the account asked for since then (4.). */
    public function countSince(int $userId, DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE password_resets_user_id = ? AND password_resets_requested_at >= ?');
        $statement->execute([$userId, self::time($since)]);

        return (int) $statement->fetchColumn();
    }

    public function setTokenHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE password_resets SET password_resets_token_hash = ? WHERE password_resets_id = ?')->execute([$hash, $id]);
    }

    /** Closes every open request of the account, but one, if named (2., 7.). */
    public function closeOpenFor(int $userId, DateTimeImmutable $now, ?int $except = null): void
    {
        $this->pdo->prepare('UPDATE password_resets SET password_resets_closed_at = ? WHERE password_resets_user_id = ? AND password_resets_closed_at IS NULL AND password_resets_id <> ?')
            ->execute([self::time($now), $userId, $except ?? 0]);
    }

    public function transactional(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /** @param list<mixed> $params */
    private function one(string $where, array $params, bool $forUpdate): ?PasswordReset
    {
        $statement = $this->pdo->prepare(self::SELECT . $where . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute($params);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : PasswordReset::fromRow($row);
    }
}
