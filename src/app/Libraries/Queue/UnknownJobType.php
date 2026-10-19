<?php

declare(strict_types=1);

namespace App\Libraries\Queue;

use LogicException;

/**
 * A job type with no handler registered under `job:<type>`: removed or
 * renamed while jobs of it were queued, or enqueued before its slice was
 * wired (docs/queue.md, failure 11).
 */
final class UnknownJobType extends LogicException
{
    public static function named(string $type): self
    {
        return new self("No handler is registered for the '{$type}' job (a container definition keyed 'job:{$type}').");
    }
}
