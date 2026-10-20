<?php

declare(strict_types=1);

use App\Models\Forms\SubmissionRepository;
use Tests\TestCase;

/*
 * The inbox's own transaction, against real commits (docs/forms.md, 12.).
 * Not transaction-wrapped, so nothing is left behind.
 */

afterEach(function (): void {
    if (TestCase::pdo()->inTransaction()) {
        TestCase::pdo()->rollBack();
    }
});

it('commits its own transaction when the caller has none, and rolls it back on failure', function (): void {
    $submissions = TestCase::container()->get(SubmissionRepository::class);

    $answer = $submissions->transactional(fn (): string => 'done');

    expect($answer)->toBe('done')
        ->and(TestCase::pdo()->inTransaction())->toBeFalse()
        ->and(fn () => $submissions->transactional(function (): void {
            throw new RuntimeException('half way');
        }))->toThrow(RuntimeException::class, 'half way')
        ->and(TestCase::pdo()->inTransaction())->toBeFalse();
});
