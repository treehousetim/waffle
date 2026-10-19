<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domain\Ports\QueuePort;
use App\Domain\Queue\Job;
use DateTimeImmutable;

/**
 * QueuePort for tests of code that enqueues (tests/Support/Overrides/queue.php):
 * it records each job instead of storing it, so a test asserts what was asked
 * for without a worker (docs/queue.md, failure 21).
 */
final class FakeQueue implements QueuePort
{
    /** @var list<array{job:Job,key:?string,run_after:?DateTimeImmutable}> */
    public array $enqueued = [];

    public function enqueue(Job $job, ?string $uniqueKey = null, ?DateTimeImmutable $runAfter = null): void
    {
        $this->enqueued[] = ['job' => $job, 'key' => $uniqueKey, 'run_after' => $runAfter];
    }
}
