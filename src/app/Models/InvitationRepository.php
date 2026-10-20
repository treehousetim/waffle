<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auth\Role;
use App\Domain\Invitations\InvitationStatus;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * The `invitations` table (docs/invitations.md). Statuses go through
 * InvitationStatus: every change asserts its edge before it is written (1., 13.).
 */
final readonly class InvitationRepository
{
    private const string SELECT = 'SELECT invitations_id, invitations_email, invitations_role, invitations_status_id, invitations_created_by,'
        . ' invitations_created_at, invitations_expires_at, invitations_decided_at, invitations_user_id FROM invitations';

    public function __construct(
        private PDO $pdo,
        private TypeRepository $types,
    ) {
    }

    public function insert(string $email, Role $role, string $tokenHash, int $createdBy, DateTimeImmutable $now, DateTimeImmutable $expiresAt): int
    {
        $this->pdo->prepare(
            'INSERT INTO invitations (invitations_email, invitations_role, invitations_token_hash, invitations_status_id,'
            . ' invitations_created_by, invitations_created_at, invitations_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$email, $role->value, $tokenHash, $this->statusId(InvitationStatus::Pending), $createdBy, self::time($now), self::time($expiresAt)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id, bool $forUpdate = false): ?Invitation
    {
        return $this->one(' WHERE invitations_id = ?', [$id], $forUpdate);
    }

    public function findByTokenHash(string $hash, bool $forUpdate = false): ?Invitation
    {
        return $this->one(' WHERE invitations_token_hash = ?', [$hash], $forUpdate);
    }

    /** @return list<Invitation> pending ones for this address, locked */
    public function pendingFor(string $email): array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE invitations_email = ? AND invitations_status_id = ? FOR UPDATE');
        $statement->execute([$email, $this->statusId(InvitationStatus::Pending)]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Invitation> newest first */
    public function all(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query(self::SELECT . ' ORDER BY invitations_id DESC')->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function decide(Invitation $invitation, InvitationStatus $next, DateTimeImmutable $now, ?int $userId = null): void
    {
        $invitation->status->assertCanTransitionTo($next);

        $this->pdo->prepare('UPDATE invitations SET invitations_status_id = ?, invitations_decided_at = ?, invitations_user_id = ? WHERE invitations_id = ?')
            ->execute([$this->statusId($next), self::time($now), $userId, $invitation->id]);
    }

    public function accountExists(string $email): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $statement->execute([$email]);

        return $statement->fetchColumn() !== false;
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
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param list<mixed> $values */
    private function one(string $where, array $values, bool $forUpdate): ?Invitation
    {
        $statement = $this->pdo->prepare(self::SELECT . $where . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute($values);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    private function statusId(InvitationStatus $status): int
    {
        return $this->types->id('invitation_status', $status->value);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Invitation
    {
        return new Invitation(
            (int) $row['invitations_id'],
            (string) $row['invitations_email'],
            Role::from((string) $row['invitations_role']),
            InvitationStatus::from($this->types->name((int) $row['invitations_status_id'])),
            (int) $row['invitations_created_by'],
            (string) $row['invitations_created_at'],
            (string) $row['invitations_expires_at'],
            $row['invitations_decided_at'] === null ? null : (string) $row['invitations_decided_at'],
            $row['invitations_user_id'] === null ? null : (int) $row['invitations_user_id'],
        );
    }
}
