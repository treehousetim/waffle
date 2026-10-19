<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use InvalidArgumentException;

/**
 * A stored payload that its job cannot rebuild itself from: written by an
 * older version of the job, or damaged (docs/queue.md, failure 10). The
 * worker marks such a job dead at once; retrying cannot fix a payload.
 */
final class InvalidJobPayload extends InvalidArgumentException
{
    public static function because(string $type, string $reason): self
    {
        return new self("The {$type} job's payload {$reason}.");
    }
}
