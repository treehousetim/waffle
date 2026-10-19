<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Queue\JobStatus;
use App\Domain\Queue\RunOutcome;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * The `jobs` and `job_runs` tables (docs/queue.md). Every query the queue
 * makes is here; the numbers in comments are the failure list's.
 *
 * Times are passed in, from ClockPort, and stored in UTC: no query compares
 * against the database's own clock (17.).
 *
 * Statuses go through JobStatus: every change asserts its edge before it is
 * written, so an illegal transition never reaches the table.
 */
final readonly class JobRepository
{
    private const string COLUMNS = 'jobs_id, jobs_type_id, jobs_payload, jobs_status_id, jobs_attempts,'
        . ' jobs_max_attempts, jobs_unique_key, jobs_run_after, jobs_last_error, jobs_created_at, jobs_finished_at';

    private const string SELECT = 'SELECT ' . self::COLUMNS . ' FROM jobs';

    /** MariaDB's error for a duplicate key, on the unique pending key (8.). */
    public const int DUPLICATE_KEY = 1062;

    public function __construct(
        private PDO $pdo,
        private TypeRepository $types,
    ) {
    }

    /**
     * Inserts a pending job. Throws a PDOException with DUPLICATE_KEY when a
     * pending job already holds $uniqueKey; DatabaseQueue merges instead.
     *
     * @param array<string,mixed> $payload
     */
    public function insert(
        string $type,
        array $payload,
        ?string $uniqueKey,
        DateTimeImmutable $runAfter,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO jobs (jobs_type_id, jobs_payload, jobs_unique_key, jobs_pending_key, jobs_status_id,'
            . ' jobs_run_after, jobs_max_attempts, jobs_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->types->id('job', $type),
            self::encode($payload),
            $uniqueKey,
            $uniqueKey,
            $this->statusId(JobStatus::Pending),
            self::time($runAfter),
            $maxAttempts,
            self::time($now),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** The pending job holding $uniqueKey, locked for the rest of the transaction. */
    public function pendingByKeyForUpdate(string $uniqueKey): ?QueuedJob
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE jobs_pending_key = ? FOR UPDATE');
        $statement->execute([$uniqueKey]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $payload */
    public function replacePayload(int $id, array $payload): void
    {
        $this->pdo->prepare('UPDATE jobs SET jobs_payload = ? WHERE jobs_id = ?')->execute([self::encode($payload), $id]);
    }

    public function find(int $id): ?QueuedJob
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE jobs_id = ?');
        $statement->execute([$id]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Claims up to $limit jobs for $worker: due pending jobs, and running jobs
     * whose lease ran out because their worker died (4.). Row locks with SKIP
     * LOCKED, so two workers never take the same job (3.).
     *
     * Each claim counts as an attempt. A job reclaimed after a lapsed lease
     * whose attempts are used up is not run again: it is recorded as
     * abandoned and marked dead.
     *
     * @return list<QueuedJob> the claimed jobs, now running
     */
    public function claim(DateTimeImmutable $now, DateTimeImmutable $leaseUntil, string $worker, int $limit): array
    {
        // READ COMMITTED: no gap locks, so one worker's claim never blocks
        // another's on rows it did not take.
        return $this->transactional(function () use ($now, $leaseUntil, $worker, $limit): array {
            $limit = max(1, $limit);
            $running = $this->statusId(JobStatus::Running);
            $claimed = [];

            // Each query walks an index in the order it asks for, so the rows
            // it locks are the rows it takes: a sort would lock every match,
            // and another worker would skip jobs this one never claims (3.).
            foreach ($this->lockedForClaim(JobStatus::Running, 'jobs_lease_until <', 'jobs_lease_until ASC', $now, $limit) as $row) {
                $job = $this->hydrate($row);

                // 4. Its worker stopped mid-run: that attempt is spent.
                $this->recordRun(
                    $job->id,
                    (string) ($row['jobs_locked_by'] ?? ''),
                    $job->attempts,
                    RunOutcome::Abandoned,
                    'The worker stopped before finishing; the lease ran out.',
                    $now,
                    $now,
                );

                if ($job->exhausted()) {
                    $this->transition($job, JobStatus::Dead, [
                        'jobs_last_error' => 'The worker stopped before finishing, on the last attempt.',
                        'jobs_finished_at' => self::time($now),
                        'jobs_locked_by' => null,
                        'jobs_lease_until' => null,
                    ]);
                    continue;
                }

                $claimed[] = $job->id;
            }

            $room = $limit - count($claimed);

            if ($room > 0) {
                JobStatus::Pending->assertCanTransitionTo(JobStatus::Running);

                foreach ($this->lockedForClaim(JobStatus::Pending, 'jobs_run_after <=', 'jobs_run_after ASC, jobs_id ASC', $now, $room) as $row) {
                    $claimed[] = (int) $row['jobs_id'];
                }
            }

            $taken = [];

            foreach ($claimed as $id) {
                $this->pdo->prepare(
                    'UPDATE jobs SET jobs_status_id = ?, jobs_pending_key = NULL, jobs_attempts = jobs_attempts + 1,'
                    . ' jobs_locked_by = ?, jobs_lease_until = ? WHERE jobs_id = ?'
                )->execute([$running, $worker, self::time($leaseUntil), $id]);

                $taken[] = $this->find($id);
            }

            return array_values(array_filter($taken));
        }, readCommitted: true);
    }

    /**
     * Rows in $status whose time column passes $condition against $now,
     * locked for this transaction and skipped if another holds them (3.).
     *
     * @return list<array<string,mixed>>
     */
    private function lockedForClaim(JobStatus $status, string $condition, string $order, DateTimeImmutable $now, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ', jobs_locked_by FROM jobs'
            . " WHERE jobs_status_id = ? AND {$condition} ?"
            . " ORDER BY {$order} LIMIT {$limit} FOR UPDATE SKIP LOCKED"
        );
        $statement->execute([$this->statusId($status), self::time($now)]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    public function markDone(QueuedJob $job, DateTimeImmutable $now): void
    {
        $this->transition($job, JobStatus::Done, [
            'jobs_finished_at' => self::time($now),
            'jobs_locked_by' => null,
            'jobs_lease_until' => null,
            'jobs_last_error' => null,
        ]);
    }

    /** Failed with attempts left: back to pending, not before $runAfter (7.). */
    public function markRetry(QueuedJob $job, DateTimeImmutable $runAfter, string $error): void
    {
        $this->transition($job, JobStatus::Pending, [
            'jobs_run_after' => self::time($runAfter),
            'jobs_last_error' => $error,
            'jobs_locked_by' => null,
            'jobs_lease_until' => null,
        ]);
    }

    public function markDead(QueuedJob $job, string $error, DateTimeImmutable $now): void
    {
        $this->transition($job, JobStatus::Dead, [
            'jobs_last_error' => $error,
            'jobs_finished_at' => self::time($now),
            'jobs_locked_by' => null,
            'jobs_lease_until' => null,
        ]);
    }

    /**
     * A claimed job its worker will not start after all (a stop signal
     * arrived first, 14.): back to pending, the claim's attempt given back.
     */
    public function release(QueuedJob $job): void
    {
        $this->transition($job, JobStatus::Pending, [
            'jobs_attempts' => max(0, $job->attempts - 1),
            'jobs_locked_by' => null,
            'jobs_lease_until' => null,
        ]);
    }

    public function recordRun(
        int $jobId,
        string $worker,
        int $attempt,
        RunOutcome $outcome,
        ?string $error,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO job_runs (job_runs_job_id, job_runs_worker, job_runs_attempt, job_runs_outcome_id,'
            . ' job_runs_error, job_runs_started_at, job_runs_finished_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $jobId,
            $worker === '' ? 'unknown' : mb_substr($worker, 0, 100),
            min(255, max(0, $attempt)),
            $this->types->id('job_run_outcome', $outcome->value),
            $error,
            self::time($startedAt),
            self::time($finishedAt),
        ]);
    }

    /**
     * @return list<array{attempt:int,outcome:string,error:?string,worker:string,started_at:string,finished_at:string}>
     */
    public function runs(int $jobId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT job_runs_attempt, job_runs_outcome_id, job_runs_error, job_runs_worker, job_runs_started_at,'
            . ' job_runs_finished_at FROM job_runs WHERE job_runs_job_id = ? ORDER BY job_runs_id ASC'
        );
        $statement->execute([$jobId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map(fn (array $row): array => [
            'attempt' => (int) $row['job_runs_attempt'],
            'outcome' => $this->types->name((int) $row['job_runs_outcome_id']),
            'error' => $row['job_runs_error'] === null ? null : (string) $row['job_runs_error'],
            'worker' => (string) $row['job_runs_worker'],
            'started_at' => (string) $row['job_runs_started_at'],
            'finished_at' => (string) $row['job_runs_finished_at'],
        ], $rows);
    }

    /**
     * What a person or the health check needs to know (16., 20.).
     *
     * @return array{pending:int,running:int,dead:int,oldest_due_seconds:int}
     */
    public function stats(DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(
            'SELECT'
            . ' SUM(jobs_status_id = ?) AS pending,'
            . ' SUM(jobs_status_id = ?) AS running,'
            . ' SUM(jobs_status_id = ?) AS dead,'
            . ' MIN(CASE WHEN jobs_status_id = ? AND jobs_run_after <= ? THEN jobs_run_after END) AS oldest_due'
            . ' FROM jobs'
        );
        $pending = $this->statusId(JobStatus::Pending);
        $statement->execute([
            $pending,
            $this->statusId(JobStatus::Running),
            $this->statusId(JobStatus::Dead),
            $pending,
            self::time($now),
        ]);

        /** @var array<string,mixed> $row */
        $row = $statement->fetch();
        $oldest = $row['oldest_due'] === null
            ? 0
            : max(0, $now->getTimestamp() - (new DateTimeImmutable((string) $row['oldest_due'] . ' UTC'))->getTimestamp());

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'running' => (int) ($row['running'] ?? 0),
            'dead' => (int) ($row['dead'] ?? 0),
            'oldest_due_seconds' => $oldest,
        ];
    }

    /** @return list<QueuedJob> dead jobs, newest first (20.) */
    public function dead(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE jobs_status_id = ? ORDER BY jobs_finished_at DESC, jobs_id DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute([$this->statusId(JobStatus::Dead)]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    /** A person retries a dead job: pending again, now, with its attempts reset (20.). */
    public function retryDead(int $id, DateTimeImmutable $now): bool
    {
        $job = $this->find($id);

        if ($job === null || $job->status !== JobStatus::Dead) {
            return false;
        }

        $this->transition($job, JobStatus::Pending, [
            'jobs_attempts' => 0,
            'jobs_run_after' => self::time($now),
            'jobs_finished_at' => null,
        ]);

        return true;
    }

    /** A person discards a dead job, and its runs with it (20.). */
    public function discardDead(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM jobs WHERE jobs_id = ? AND jobs_status_id = ?');
        $statement->execute([$id, $this->statusId(JobStatus::Dead)]);

        return $statement->rowCount() === 1;
    }

    /** Deletes jobs finished before $before; dead ones stay for a person (15.). */
    public function pruneFinished(DateTimeImmutable $before): int
    {
        $statement = $this->pdo->prepare('DELETE FROM jobs WHERE jobs_status_id = ? AND jobs_finished_at < ?');
        $statement->execute([$this->statusId(JobStatus::Done), self::time($before)]);

        return $statement->rowCount();
    }

    public static function isDuplicateKey(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE_KEY;
    }

    /**
     * Runs $work in a transaction, or in the caller's when one is open: an
     * enqueue inside a request's transaction must commit or roll back with it
     * (1., 2.), and a transaction cannot be nested.
     *
     * @template T
     *
     * @param callable(): T $work
     * @param bool          $readCommitted run it at READ COMMITTED, when it opens its own
     *
     * @return T
     */
    public function transactional(callable $work, bool $readCommitted = false): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        if ($readCommitted) {
            // Applies to the next transaction only.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
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

    /**
     * @param array<string,scalar|null> $columns more columns to set with the status
     */
    private function transition(QueuedJob $job, JobStatus $next, array $columns): void
    {
        $job->status->assertCanTransitionTo($next);

        $columns = ['jobs_status_id' => $this->statusId($next)] + $columns;
        $set = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($columns)));

        $this->pdo->prepare("UPDATE jobs SET {$set} WHERE jobs_id = ?")->execute([...array_values($columns), $job->id]);
    }

    private function statusId(JobStatus $status): int
    {
        return $this->types->id('job_status', $status->value);
    }

    /** @param array<string,mixed> $payload */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): QueuedJob
    {
        $payload = json_decode((string) $row['jobs_payload'], true);

        return new QueuedJob(
            (int) $row['jobs_id'],
            $this->types->name((int) $row['jobs_type_id']),
            is_array($payload) ? $payload : [],
            JobStatus::from($this->types->name((int) $row['jobs_status_id'])),
            (int) $row['jobs_attempts'],
            (int) $row['jobs_max_attempts'],
            $row['jobs_unique_key'] === null ? null : (string) $row['jobs_unique_key'],
            (string) $row['jobs_run_after'],
            $row['jobs_last_error'] === null ? null : (string) $row['jobs_last_error'],
            (string) $row['jobs_created_at'],
            $row['jobs_finished_at'] === null ? null : (string) $row['jobs_finished_at'],
        );
    }
}
