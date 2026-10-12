<?php

declare(strict_types=1);

use App\Domain\Status\IllegalTransition;
use Tests\Support\Fixtures\DoorStatus;

/*
 * The state-machine trait every status enum uses. Written against a fixture
 * enum; a real status enum's own test walks every allowed and forbidden edge
 * the same way (the last test below is the shape to copy).
 */

it('allows the edges the enum declares', function (): void {
    expect(DoorStatus::Closed->canTransitionTo(DoorStatus::Open))->toBeTrue()
        ->and(DoorStatus::Open->canTransitionTo(DoorStatus::Closed))->toBeTrue();
});

it('refuses an edge the enum does not declare, naming the machine and both states', function (): void {
    expect(DoorStatus::Open->canTransitionTo(DoorStatus::Locked))->toBeFalse()
        ->and(fn () => DoorStatus::Open->assertCanTransitionTo(DoorStatus::Locked))
        ->toThrow(IllegalTransition::class, "A door cannot go from 'open' to 'locked'.");
});

it('does nothing when asked to assert an allowed edge', function (): void {
    expect(fn () => DoorStatus::Closed->assertCanTransitionTo(DoorStatus::Locked))
        ->not->toThrow(IllegalTransition::class);
});

it('treats a state with no way out as terminal', function (): void {
    expect(DoorStatus::Removed->isTerminal())->toBeTrue()
        ->and(DoorStatus::Closed->isTerminal())->toBeFalse();
});

it('decides every pair of states, one way or the other', function (): void {
    // The exhaustive walk: every (from, to) pair is either an edge or an
    // IllegalTransition, and nothing in between.
    foreach (DoorStatus::cases() as $from) {
        foreach (DoorStatus::cases() as $to) {
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
