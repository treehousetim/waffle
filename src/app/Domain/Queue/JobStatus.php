<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use App\Domain\Status\HasTransitions;

/**
 * Where a job is in its life (docs/queue.md).
 *
 *   pending → running → done
 *                     → pending   (failed, attempts left: retried with backoff)
 *                     → dead      (failed its last attempt, or cannot run)
 *   dead → pending                (a person retries it)
 *
 * Also the `types` group `job_status`; VocabularyTest keeps the two in step.
 */
enum JobStatus: string
{
    use HasTransitions;

    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Dead = 'dead';

    public static function machineName(): string
    {
        return 'job';
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Running],
            self::Running => [self::Done, self::Pending, self::Dead],
            self::Dead => [self::Pending],
            self::Done => [],
        };
    }
}
