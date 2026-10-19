<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use InvalidArgumentException;

/**
 * The queue's policy, in one place (docs/queue.md). The defaults suit a small
 * app; a project that needs others changes them in its container definition.
 */
final readonly class QueueSettings
{
    public function __construct(
        /** How long a claim holds before another worker may take the job (4.). */
        public int $leaseSeconds = 300,
        /** Attempts before a job is dead (6.). */
        public int $maxAttempts = 5,
        /** The first retry's delay; each later one doubles, up to the cap (7.). */
        public int $backoffSeconds = 30,
        public int $backoffCapSeconds = 3600,
        /** A due job waiting longer than this means the worker has stopped (16.). */
        public int $stallSeconds = 900,
        /** Finished jobs are deleted after this many days (15.). */
        public int $keepFinishedDays = 14,
        /** Jobs claimed at once by a worker. */
        public int $batchSize = 10,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("Queue setting {$name} must be at least 1.");
            }
        }

        if ($this->maxAttempts > 255) {
            throw new InvalidArgumentException('Queue setting maxAttempts must be at most 255.');
        }
    }

    /**
     * How long to wait before attempt number $attempt + 1, after attempt
     * $attempt failed: the base doubled per attempt, capped, plus jitter so
     * that jobs failing together do not retry together (7.).
     */
    public function retryDelaySeconds(int $attempt, int $jitter): int
    {
        $exponent = min(max($attempt - 1, 0), 30);
        $delay = min($this->backoffSeconds * (2 ** $exponent), $this->backoffCapSeconds);

        return $delay + max(0, min($jitter, $this->backoffSeconds));
    }
}
