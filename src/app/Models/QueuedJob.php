<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Queue\JobStatus;

/** One row of `jobs`, as the queue reads it. */
final readonly class QueuedJob
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public int $id,
        public string $type,
        public array $payload,
        public JobStatus $status,
        public int $attempts,
        public int $maxAttempts,
        public ?string $uniqueKey,
        public string $runAfter,
        public ?string $lastError,
        public string $createdAt,
        public ?string $finishedAt,
    ) {
    }

    /** True when the attempt that just ran (or was abandoned) was its last. */
    public function exhausted(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }
}
