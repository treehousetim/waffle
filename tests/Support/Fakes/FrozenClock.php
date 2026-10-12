<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domain\Ports\ClockPort;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock that stands still until told to move. Bound in place of the real
 * one for every test (tests/Support/Overrides/core.php), so nothing in the
 * suite depends on when it runs.
 */
final class FrozenClock implements ClockPort
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-01-01 12:00:00')
    {
        $this->now = new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
