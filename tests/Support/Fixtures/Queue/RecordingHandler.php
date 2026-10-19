<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Queue;

use App\Domain\Queue\Job;
use App\Domain\Queue\JobHandler;
use RuntimeException;

/**
 * Runs RecordingJob by remembering it. Told to fail, it throws that many
 * times first, so a test can walk a job through its retries. Not final: a
 * test extends it to do something else while handling.
 */
class RecordingHandler implements JobHandler
{
    /** @var list<list<string>> the slugs of every job handled, in order */
    public array $handled = [];

    public function __construct(public int $failures = 0)
    {
    }

    public function jobClass(): string
    {
        return RecordingJob::class;
    }

    public function handle(Job $job): void
    {
        if ($this->failures > 0) {
            --$this->failures;

            throw new RuntimeException('The recording handler was told to fail.');
        }

        /** @var RecordingJob $job */
        $this->handled[] = $job->slugs;
    }
}
