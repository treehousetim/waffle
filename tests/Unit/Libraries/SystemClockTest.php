<?php

declare(strict_types=1);

use App\Libraries\Clock\SystemClock;
use Tests\Support\Fakes\FrozenClock;

it('tells the time now, in UTC', function (): void {
    $before = time();
    $now = (new SystemClock())->now();

    expect($now->getTimezone()->getName())->toBe('UTC')
        ->and($now->getTimestamp())->toBeGreaterThanOrEqual($before)
        ->and($now->getTimestamp())->toBeLessThanOrEqual(time());
});

it('has a frozen twin for tests that only moves when told', function (): void {
    $clock = new FrozenClock('2026-03-01 09:00:00');

    $clock->advance('+90 minutes');

    expect($clock->now()->format('Y-m-d H:i'))->toBe('2026-03-01 10:30')
        ->and($clock->now()->getTimezone()->getName())->toBe('UTC');
});
