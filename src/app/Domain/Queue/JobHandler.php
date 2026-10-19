<?php

declare(strict_types=1);

namespace App\Domain\Queue;

/**
 * Runs one type of job. Registered in a container fragment under the key
 * `job:<type>` (docs/queue.md), and resolved by the worker, never by a
 * controller (18.).
 *
 * handle() must be idempotent: running it twice for the same job leaves the
 * same result as running it once (5.). Throwing means "this attempt failed";
 * the queue retries with backoff, then gives up and marks the job dead.
 */
interface JobHandler
{
    /** @return class-string<Job> the job this handler runs */
    public function jobClass(): string;

    public function handle(Job $job): void;
}
