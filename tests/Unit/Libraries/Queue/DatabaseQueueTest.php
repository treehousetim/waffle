<?php

declare(strict_types=1);

use App\Domain\Queue\JobStatus;
use App\Domain\Queue\QueueSettings;
use App\Libraries\Queue\UnknownJobType;
use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Fixtures\Queue\RecordingHandler;
use Tests\Support\Fixtures\Queue\RecordingJob;
use Tests\Support\QueueFixtures;
use Tests\TestCase;

/*
 * QueuePort kept in MariaDB (docs/queue.md). Committing and rolling back with
 * the caller's transaction (1., 2.) is proven in tests/Schema/QueueTransactionTest.php,
 * where a transaction can really commit.
 */

beforeEach(function (): void {
    QueueFixtures::registerTestJobType();
    $this->clock = new FrozenClock();
    $this->queue = QueueFixtures::queue(new RecordingHandler(), $this->clock, new QueueSettings(maxAttempts: 4));
    $this->jobs = QueueFixtures::repository();
});

function pendingJobs(): array
{
    return TestCase::pdo()->query('SELECT jobs_id, jobs_payload, jobs_run_after, jobs_max_attempts, jobs_unique_key FROM jobs ORDER BY jobs_id')->fetchAll();
}

it('stores a job to run now, with the policy\'s attempts', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']));

    $rows = pendingJobs();

    expect($rows)->toHaveCount(1)
        ->and(json_decode($rows[0]['jobs_payload'], true))->toBe(['slugs' => ['a']])
        ->and($rows[0]['jobs_run_after'])->toBe('2026-01-01 12:00:00')
        ->and($rows[0]['jobs_max_attempts'])->toBe(4)
        ->and($rows[0]['jobs_unique_key'])->toBeNull();
});

it('stores a delayed job to run later', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']), runAfter: $this->clock->now()->modify('+1 minute'));

    expect(pendingJobs()[0]['jobs_run_after'])->toBe('2026-01-01 12:01:00');
});

it('merges a job into the pending one with the same key, keeping the first one\'s time (8)', function (): void {
    $this->queue->enqueue(new RecordingJob(['b']), 'site', $this->clock->now()->modify('+1 minute'));
    $this->queue->enqueue(new RecordingJob(['a', 'b']), 'site', $this->clock->now()->modify('+5 minutes'));
    $this->queue->enqueue(new RecordingJob(['c']), 'other');

    $rows = pendingJobs();

    expect($rows)->toHaveCount(2)
        ->and(json_decode($rows[0]['jobs_payload'], true))->toBe(['slugs' => ['a', 'b']])
        ->and($rows[0]['jobs_run_after'])->toBe('2026-01-01 12:01:00')
        ->and(json_decode($rows[1]['jobs_payload'], true))->toBe(['slugs' => ['c']]);
});

it('does not merge into a job already running: the new one waits behind it (9)', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']), 'site');
    $this->jobs->claim($this->clock->now(), $this->clock->now()->modify('+5 minutes'), 'worker-a', 1);
    $this->queue->enqueue(new RecordingJob(['b']), 'site');

    $rows = pendingJobs();

    expect($rows)->toHaveCount(2)
        ->and($this->jobs->find((int) $rows[1]['jobs_id'])->status)->toBe(JobStatus::Pending)
        ->and(json_decode($rows[1]['jobs_payload'], true))->toBe(['slugs' => ['b']]);
});

it('replaces a pending payload it can no longer read instead of failing (10)', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']), 'site');
    TestCase::pdo()->exec('UPDATE jobs SET jobs_payload = \'{"old":"shape"}\'');

    $this->queue->enqueue(new RecordingJob(['b']), 'site');

    expect(json_decode(pendingJobs()[0]['jobs_payload'], true))->toBe(['slugs' => ['b']]);
});

it('refuses a job nothing is registered to run (11)', function (): void {
    $queue = new App\Libraries\Queue\DatabaseQueue(
        $this->jobs,
        new App\Libraries\Queue\JobRegistry(new DI\Container([]), []),
        $this->clock,
        new QueueSettings(),
    );

    expect(fn () => $queue->enqueue(new RecordingJob(['a'])))
        ->toThrow(UnknownJobType::class, "No handler is registered for the 'test-recording' job");
});

it('passes on a database error that is not a duplicate key', function (): void {
    expect(fn () => $this->queue->enqueue(new RecordingJob(['a']), str_repeat('k', 300)))
        ->toThrow(PDOException::class);
});
