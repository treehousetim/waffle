<?php

declare(strict_types=1);

namespace App\Libraries\Clock;

use App\Domain\Ports\ClockPort;
use DateTimeImmutable;
use DateTimeZone;

/** The wall clock, in UTC. Tests use tests/Support/Fakes/FrozenClock. */
final readonly class SystemClock implements ClockPort
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
