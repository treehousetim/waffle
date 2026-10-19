<?php

declare(strict_types=1);

namespace App\Domain\Ports;

use App\Domain\Queue\Job;
use DateTimeImmutable;

/**
 * Run a job later, outside this request (docs/queue.md).
 *
 * The real adapter writes through the same database connection as the code
 * that calls it, so an enqueue inside a transaction commits or rolls back with
 * the change it describes (1., 2.). Tests bind tests/Support/Fakes/FakeQueue,
 * which records what was enqueued.
 */
interface QueuePort
{
    /**
     * @param string|null            $uniqueKey a pending job with the same key absorbs this one
     *                                          (Job::merge) instead of a second row (8.)
     * @param DateTimeImmutable|null $runAfter  not before this instant, in UTC; now when null
     */
    public function enqueue(Job $job, ?string $uniqueKey = null, ?DateTimeImmutable $runAfter = null): void;
}
