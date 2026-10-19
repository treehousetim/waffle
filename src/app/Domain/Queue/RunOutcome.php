<?php

declare(strict_types=1);

namespace App\Domain\Queue;

/**
 * How one attempt at a job ended, as recorded in `job_runs` (docs/queue.md).
 * Also the `types` group `job_run_outcome`.
 */
enum RunOutcome: string
{
    case Done = 'done';
    /** It threw, and has attempts left: it will be retried. */
    case Failed = 'failed';
    /** It threw on its last attempt, or could not be run at all. */
    case Dead = 'dead';
    /** Its worker stopped mid-run and the lease ran out (4.). */
    case Abandoned = 'abandoned';
}
