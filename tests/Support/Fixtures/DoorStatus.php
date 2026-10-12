<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Domain\Status\HasTransitions;

/**
 * A state machine that exists only to test HasTransitions, until the
 * application has real ones. Shaped exactly like a real status enum should be:
 * backed, with its edges declared, and a terminal state.
 *
 *   closed -> open -> closed
 *   closed -> locked -> closed
 *   any    -> removed   (terminal)
 */
enum DoorStatus: string
{
    use HasTransitions;

    case Closed = 'closed';
    case Open = 'open';
    case Locked = 'locked';
    case Removed = 'removed';

    public function allowedNext(): array
    {
        return match ($this) {
            self::Closed => [self::Open, self::Locked, self::Removed],
            self::Open => [self::Closed, self::Removed],
            self::Locked => [self::Closed, self::Removed],
            self::Removed => [],
        };
    }

    public static function machineName(): string
    {
        return 'door';
    }
}
