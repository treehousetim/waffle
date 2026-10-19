<?php

declare(strict_types=1);

namespace App\Libraries\Queue;

use App\Domain\Ports\ClockPort;
use App\Domain\Ports\QueuePort;
use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\Job;
use App\Domain\Queue\QueueSettings;
use App\Models\JobRepository;
use App\Models\QueuedJob;
use DateTimeImmutable;
use PDOException;

/**
 * QueuePort, kept in MariaDB (docs/queue.md).
 *
 * It writes through the application's own connection, so a job enqueued
 * inside a transaction exists exactly when the change it describes does
 * (1., 2.). With a unique key, a pending job already holding the key absorbs
 * the new one through Job::merge instead of a second row (8.).
 */
final readonly class DatabaseQueue implements QueuePort
{
    public function __construct(
        private JobRepository $jobs,
        private JobRegistry $registry,
        private ClockPort $clock,
        private QueueSettings $settings,
    ) {
    }

    public function enqueue(Job $job, ?string $uniqueKey = null, ?DateTimeImmutable $runAfter = null): void
    {
        $type = $job::type();

        // 11. A job nothing can run is refused here, where the caller sees it,
        // rather than dying unread in the table.
        if (!$this->registry->has($type)) {
            throw UnknownJobType::named($type);
        }

        $runAfter ??= $this->clock->now();

        if ($uniqueKey === null) {
            $this->insert($job, null, $runAfter);

            return;
        }

        $this->jobs->transactional(function () use ($job, $uniqueKey, $runAfter): void {
            // Insert first: the unique pending key decides, inside the
            // database, whether a pending job already holds this key, so two
            // requests racing to enqueue cannot both add one (8.).
            try {
                $this->insert($job, $uniqueKey, $runAfter);

                return;
            } catch (PDOException $e) {
                if (!JobRepository::isDuplicateKey($e)) {
                    throw $e;
                }
            }

            // The pending job holding the key absorbs this one. The failed
            // insert left a shared lock on that key, so no worker can claim
            // the job before this merge commits; FOR UPDATE makes a second
            // merge wait for this one.
            $pending = $this->jobs->pendingByKeyForUpdate($uniqueKey) ?? throw $e;

            $this->jobs->replacePayload($pending->id, $this->merged($pending, $job)->payload());
        });
    }

    private function insert(Job $job, ?string $uniqueKey, DateTimeImmutable $runAfter): void
    {
        $this->jobs->insert(
            $job::type(),
            $job->payload(),
            $uniqueKey,
            $runAfter,
            $this->settings->maxAttempts,
            $this->clock->now(),
        );
    }

    private function merged(QueuedJob $pending, Job $newer): Job
    {
        $class = $this->registry->handler($pending->type)->jobClass();

        try {
            return $class::fromPayload($pending->payload)->merge($newer);
        } catch (InvalidJobPayload) {
            // 10. The queued payload is one this code cannot read: the newer
            // job replaces it rather than leaving both unrunnable.
            return $newer;
        }
    }
}
