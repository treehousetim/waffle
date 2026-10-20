<?php

declare(strict_types=1);

namespace App\Models\Forms;

use App\Domain\Forms\SubmissionStatus;
use App\Models\TypeRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** The `form_submissions` table (docs/forms.md). Statuses go through SubmissionStatus (12.). */
final readonly class SubmissionRepository
{
    private const string SELECT = 'SELECT s.form_submissions_id, f.types_name AS form, s.form_submissions_site_id, s.form_submissions_payload,'
        . ' s.form_submissions_status_id, s.form_submissions_created_at, s.form_submissions_decided_at'
        . ' FROM form_submissions s JOIN types f ON f.types_id = s.form_submissions_form_id';

    public function __construct(
        private PDO $pdo,
        private TypeRepository $types,
    ) {
    }

    /** @param array<string,string> $payload */
    public function insert(string $form, int $siteId, array $payload, string $ipHash, DateTimeImmutable $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO form_submissions (form_submissions_form_id, form_submissions_site_id, form_submissions_payload,'
            . ' form_submissions_status_id, form_submissions_ip_hash, form_submissions_created_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->types->id('form', $form),
            $siteId,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->statusId(SubmissionStatus::New),
            $ipHash,
            self::time($now),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** How many submissions this visitor hash made since then (4.). */
    public function countSince(string $ipHash, DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM form_submissions WHERE form_submissions_ip_hash = ? AND form_submissions_created_at >= ?');
        $statement->execute([$ipHash, self::time($since)]);

        return (int) $statement->fetchColumn();
    }

    public function find(int $id, bool $forUpdate = false): ?Submission
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE s.form_submissions_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<Submission> newest first */
    public function withStatus(SubmissionStatus $status): array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE s.form_submissions_status_id = ? ORDER BY s.form_submissions_id DESC LIMIT 500');
        $statement->execute([$this->statusId($status)]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function decide(Submission $submission, SubmissionStatus $next, int $by, DateTimeImmutable $now): void
    {
        $submission->status->assertCanTransitionTo($next);

        $this->pdo->prepare('UPDATE form_submissions SET form_submissions_status_id = ?, form_submissions_decided_by = ?, form_submissions_decided_at = ? WHERE form_submissions_id = ?')
            ->execute([$this->statusId($next), $by, self::time($now), $submission->id]);
    }

    /** Deletes every submission made before then (11.); how many went. */
    public function pruneBefore(DateTimeImmutable $before): int
    {
        $statement = $this->pdo->prepare('DELETE FROM form_submissions WHERE form_submissions_created_at < ?');
        $statement->execute([self::time($before)]);

        return $statement->rowCount();
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

    private function statusId(SubmissionStatus $status): int
    {
        return $this->types->id('submission_status', $status->value);
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Submission
    {
        /** @var array<string,string> $payload */
        $payload = json_decode((string) $row['form_submissions_payload'], true, 8, JSON_THROW_ON_ERROR);

        return new Submission(
            (int) $row['form_submissions_id'],
            (string) $row['form'],
            (int) $row['form_submissions_site_id'],
            $payload,
            SubmissionStatus::from($this->types->name((int) $row['form_submissions_status_id'])),
            (string) $row['form_submissions_created_at'],
            $row['form_submissions_decided_at'] === null ? null : (string) $row['form_submissions_decided_at'],
        );
    }
}
