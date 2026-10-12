<?php

declare(strict_types=1);

namespace App\Domain\Status;

use DomainException;

/**
 * A status change the state machine does not allow.
 *
 * Repositories never set a status directly; they go through the enum, so this
 * is raised before anything reaches the database.
 */
final class IllegalTransition extends DomainException
{
    public static function between(string $machine, string $from, string $to): self
    {
        return new self("A {$machine} cannot go from '{$from}' to '{$to}'.");
    }
}
