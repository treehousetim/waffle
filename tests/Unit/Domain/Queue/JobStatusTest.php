<?php

declare(strict_types=1);

use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\JobStatus;
use App\Domain\Status\IllegalTransition;

/*
 * A job's life (docs/queue.md): pending → running → done, back to pending to
 * retry, or dead; a person sends a dead job back to pending.
 */

it('runs, finishes, retries and dies along its declared edges', function (): void {
    expect(JobStatus::Pending->allowedNext())->toBe([JobStatus::Running])
        ->and(JobStatus::Running->allowedNext())->toBe([JobStatus::Done, JobStatus::Pending, JobStatus::Dead])
        ->and(JobStatus::Dead->allowedNext())->toBe([JobStatus::Pending])
        ->and(JobStatus::Done->isTerminal())->toBeTrue();
});

it('names itself in a refusal', function (): void {
    expect(fn () => JobStatus::Done->assertCanTransitionTo(JobStatus::Pending))
        ->toThrow(IllegalTransition::class, "A job cannot go from 'done' to 'pending'.");
});

it('decides every pair of statuses, one way or the other', function (): void {
    foreach (JobStatus::cases() as $from) {
        foreach (JobStatus::cases() as $to) {
            $allowed = in_array($to, $from->allowedNext(), true);

            try {
                $from->assertCanTransitionTo($to);
                $threw = false;
            } catch (IllegalTransition) {
                $threw = true;
            }

            expect($threw)->toBe(!$allowed, "{$from->value} -> {$to->value}");
        }
    }
});

it('says which job could not read its payload, and why (10)', function (): void {
    expect(InvalidJobPayload::because('site-change-notice', 'has no slugs')->getMessage())
        ->toBe("The site-change-notice job's payload has no slugs.");
});
