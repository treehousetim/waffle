<?php

declare(strict_types=1);

namespace App\Domain\Ports;

use DateTimeImmutable;

/**
 * What time it is.
 *
 * Domain code that needs "now" asks this rather than calling `new
 * DateTimeImmutable()`, so a test can stand still at a known instant
 * (tests/Support/Fakes/FrozenClock) instead of racing the wall clock. Always
 * UTC; conversion to a display zone happens in display queries and the
 * frontend.
 */
interface ClockPort
{
    public function now(): DateTimeImmutable;
}
