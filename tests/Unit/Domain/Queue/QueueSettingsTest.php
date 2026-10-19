<?php

declare(strict_types=1);

use App\Domain\Queue\QueueSettings;

it('defaults to a policy a small app can run on', function (): void {
    $settings = new QueueSettings();

    expect($settings->leaseSeconds)->toBe(300)
        ->and($settings->maxAttempts)->toBe(5)
        ->and($settings->backoffSeconds)->toBe(30)
        ->and($settings->backoffCapSeconds)->toBe(3600)
        ->and($settings->stallSeconds)->toBe(900)
        ->and($settings->keepFinishedDays)->toBe(14)
        ->and($settings->batchSize)->toBe(10);
});

it('refuses a setting below one, naming it', function (): void {
    expect(fn () => new QueueSettings(leaseSeconds: 0))
        ->toThrow(InvalidArgumentException::class, 'Queue setting leaseSeconds must be at least 1.');
});

it('refuses more attempts than the column holds', function (): void {
    expect(fn () => new QueueSettings(maxAttempts: 256))
        ->toThrow(InvalidArgumentException::class, 'Queue setting maxAttempts must be at most 255.');
});

it('doubles the delay after each failed attempt, up to the cap (7)', function (): void {
    $settings = new QueueSettings(backoffSeconds: 30, backoffCapSeconds: 300);

    expect($settings->retryDelaySeconds(1, 0))->toBe(30)
        ->and($settings->retryDelaySeconds(2, 0))->toBe(60)
        ->and($settings->retryDelaySeconds(3, 0))->toBe(120)
        ->and($settings->retryDelaySeconds(4, 0))->toBe(240)
        ->and($settings->retryDelaySeconds(5, 0))->toBe(300)
        ->and($settings->retryDelaySeconds(99, 0))->toBe(300);
});

it('adds jitter of at most one base delay, so failures together do not retry together (7)', function (): void {
    $settings = new QueueSettings(backoffSeconds: 30);

    expect($settings->retryDelaySeconds(1, 7))->toBe(37)
        ->and($settings->retryDelaySeconds(1, 500))->toBe(60)
        ->and($settings->retryDelaySeconds(1, -5))->toBe(30)
        ->and($settings->retryDelaySeconds(0, 0))->toBe(30);
});
