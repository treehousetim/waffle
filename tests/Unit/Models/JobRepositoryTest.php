<?php

declare(strict_types=1);

use App\Domain\Queue\JobStatus;
use App\Domain\Status\IllegalTransition;
use App\Models\JobRepository;
use Tests\Support\QueueFixtures;

/*
 * The queue's SQL (docs/queue.md), against the migrated schema inside the
 * rolled-back transaction. The numbers are the failure list's.
 */

beforeEach(function (): void {
    QueueFixtures::registerTestJobType();
    $this->jobs = QueueFixtures::repository();
    $this->now = new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC'));
});

function insertJob(JobRepository $jobs, DateTimeImmutable $now, array $slugs = ['a'], ?string $key = null, string $runAfter = '+0 seconds'): int
{
    return $jobs->insert('test-recording', ['slugs' => $slugs], $key, $now->modify($runAfter), 3, $now);
}

it('inserts a pending job and reads it back', function (): void {
    $id = insertJob($this->jobs, $this->now, ['a', 'b'], 'site');
    $job = $this->jobs->find($id);

    expect($job->type)->toBe('test-recording')
        ->and($job->payload)->toBe(['slugs' => ['a', 'b']])
        ->and($job->status)->toBe(JobStatus::Pending)
        ->and($job->attempts)->toBe(0)
        ->and($job->maxAttempts)->toBe(3)
        ->and($job->uniqueKey)->toBe('site')
        ->and($job->runAfter)->toBe('2026-01-01 12:00:00')
        ->and($job->createdAt)->toBe('2026-01-01 12:00:00')
        ->and($job->finishedAt)->toBeNull()
        ->and($job->lastError)->toBeNull()
        ->and($this->jobs->find(999_999))->toBeNull();
});

it('stores times in UTC whatever zone they arrive in (17)', function (): void {
    $id = $this->jobs->insert('test-recording', ['slugs' => []], null,
        new DateTimeImmutable('2026-01-01 07:00:00', new DateTimeZone('America/New_York')), 3, $this->now);

    expect($this->jobs->find($id)->runAfter)->toBe('2026-01-01 12:00:00');
});

it('refuses a second pending job with the same key (8)', function (): void {
    insertJob($this->jobs, $this->now, key: 'site');

    try {
        insertJob($this->jobs, $this->now, key: 'site');
        $duplicate = false;
    } catch (PDOException $e) {
        $duplicate = JobRepository::isDuplicateKey($e);
    }

    expect($duplicate)->toBeTrue()
        ->and(JobRepository::isDuplicateKey(new PDOException('not from the database')))->toBeFalse();
});

it('finds the pending job holding a key, and changes its payload', function (): void {
    $id = insertJob($this->jobs, $this->now, ['a'], 'site');

    $this->jobs->replacePayload($id, ['slugs' => ['a', 'b']]);

    expect($this->jobs->pendingByKeyForUpdate('site')->id)->toBe($id)
        ->and($this->jobs->find($id)->payload)->toBe(['slugs' => ['a', 'b']])
        ->and($this->jobs->pendingByKeyForUpdate('nobody'))->toBeNull();
});

it('claims due jobs in order, and not jobs that are not yet due (17)', function (): void {
    $later = insertJob($this->jobs, $this->now, runAfter: '+1 minute');
    $second = insertJob($this->jobs, $this->now, runAfter: '-1 minute');
    $first = insertJob($this->jobs, $this->now, runAfter: '-2 minutes');

    $claimed = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 10);

    expect(array_map(static fn ($job): int => $job->id, $claimed))->toBe([$first, $second])
        ->and($claimed[0]->status)->toBe(JobStatus::Running)
        ->and($claimed[0]->attempts)->toBe(1)
        ->and($this->jobs->find($later)->status)->toBe(JobStatus::Pending);
});

it('claims at most a batch', function (): void {
    foreach (range(1, 3) as $n) {
        insertJob($this->jobs, $this->now);
    }

    expect($this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 2))->toHaveCount(2);
});

it('frees a claimed job\'s key, so a new job with it can wait behind it (9)', function (): void {
    insertJob($this->jobs, $this->now, key: 'site');
    $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 1);

    expect($this->jobs->pendingByKeyForUpdate('site'))->toBeNull()
        ->and(insertJob($this->jobs, $this->now, key: 'site'))->toBeInt();
});

it('takes back a running job whose lease ran out, and records the abandoned attempt (4)', function (): void {
    $id = insertJob($this->jobs, $this->now);
    $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 1);

    $later = $this->now->modify('+6 minutes');
    $reclaimed = $this->jobs->claim($later, $later->modify('+5 minutes'), 'worker-b', 1);

    expect($reclaimed)->toHaveCount(1)
        ->and($reclaimed[0]->attempts)->toBe(2)
        ->and($this->jobs->runs($id))->toBe([[
            'attempt' => 1,
            'outcome' => 'abandoned',
            'error' => 'The worker stopped before finishing; the lease ran out.',
            'worker' => 'worker-a',
            'started_at' => '2026-01-01 12:06:00',
            'finished_at' => '2026-01-01 12:06:00',
        ]]);
});

it('does not take back a running job whose lease still holds (3)', function (): void {
    insertJob($this->jobs, $this->now);
    $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 1);

    expect($this->jobs->claim($this->now->modify('+1 minute'), $this->now->modify('+6 minutes'), 'worker-b', 1))->toBe([]);
});

it('gives up on an abandoned job whose last attempt it was (4, 6)', function (): void {
    $id = $this->jobs->insert('test-recording', ['slugs' => []], null, $this->now, 1, $this->now);
    $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 1);

    $later = $this->now->modify('+6 minutes');

    expect($this->jobs->claim($later, $later->modify('+5 minutes'), 'worker-b', 1))->toBe([])
        ->and($this->jobs->find($id)->status)->toBe(JobStatus::Dead)
        ->and($this->jobs->find($id)->lastError)->toBe('The worker stopped before finishing, on the last attempt.')
        ->and($this->jobs->find($id)->finishedAt)->toBe('2026-01-01 12:06:00');
});

it('marks a job done, retried or dead, and records each run', function (): void {
    insertJob($this->jobs, $this->now);
    insertJob($this->jobs, $this->now);
    insertJob($this->jobs, $this->now);
    [$done, $retry, $dead] = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 3);

    $this->jobs->markDone($done, $this->now);
    $this->jobs->markRetry($retry, $this->now->modify('+30 seconds'), 'Boom');
    $this->jobs->markDead($dead, 'Gave up', $this->now);
    $this->jobs->recordRun($done->id, '', 1, App\Domain\Queue\RunOutcome::Done, null, $this->now, $this->now);

    expect($this->jobs->find($done->id)->status)->toBe(JobStatus::Done)
        ->and($this->jobs->find($done->id)->finishedAt)->toBe('2026-01-01 12:00:00')
        ->and($this->jobs->find($retry->id)->status)->toBe(JobStatus::Pending)
        ->and($this->jobs->find($retry->id)->runAfter)->toBe('2026-01-01 12:00:30')
        ->and($this->jobs->find($retry->id)->lastError)->toBe('Boom')
        ->and($this->jobs->find($dead->id)->status)->toBe(JobStatus::Dead)
        ->and($this->jobs->runs($done->id)[0]['worker'])->toBe('unknown')
        ->and($this->jobs->runs($done->id)[0]['outcome'])->toBe('done');
});

it('refuses to finish a job that is not running', function (): void {
    $job = $this->jobs->find(insertJob($this->jobs, $this->now));

    expect(fn () => $this->jobs->markDone($job, $this->now))->toThrow(IllegalTransition::class);
});

it('gives a released job its attempt back (14)', function (): void {
    insertJob($this->jobs, $this->now);
    [$job] = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 1);

    $this->jobs->release($job);

    expect($this->jobs->find($job->id)->status)->toBe(JobStatus::Pending)
        ->and($this->jobs->find($job->id)->attempts)->toBe(0);
});

it('counts what is waiting, running and dead, and how long the oldest due job has waited (16)', function (): void {
    expect($this->jobs->stats($this->now))->toBe(['pending' => 0, 'running' => 0, 'dead' => 0, 'oldest_due_seconds' => 0]);

    insertJob($this->jobs, $this->now, runAfter: '-10 minutes');
    insertJob($this->jobs, $this->now, runAfter: '+10 minutes');
    insertJob($this->jobs, $this->now, runAfter: '-20 minutes');
    [$running] = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 1);
    $this->jobs->markDead($running, 'Gave up', $this->now);
    insertJob($this->jobs, $this->now, runAfter: '-1 minute');
    $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 1);

    expect($this->jobs->stats($this->now))->toBe(['pending' => 2, 'running' => 1, 'dead' => 1, 'oldest_due_seconds' => 60]);
});

it('lists dead jobs newest first, and lets a person retry or discard one (20)', function (): void {
    insertJob($this->jobs, $this->now);
    insertJob($this->jobs, $this->now);
    [$older, $newer] = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 2);
    $this->jobs->markDead($older, 'First', $this->now);
    $this->jobs->markDead($newer, 'Second', $this->now->modify('+1 minute'));

    expect(array_map(static fn ($job): int => $job->id, $this->jobs->dead()))->toBe([$newer->id, $older->id]);

    $retried = $this->jobs->retryDead($older->id, $this->now->modify('+1 hour'));
    $job = $this->jobs->find($older->id);

    expect($retried)->toBeTrue()
        ->and($job->status)->toBe(JobStatus::Pending)
        ->and($job->attempts)->toBe(0)
        ->and($job->runAfter)->toBe('2026-01-01 13:00:00')
        ->and($job->finishedAt)->toBeNull()
        ->and($this->jobs->retryDead($older->id, $this->now))->toBeFalse()
        ->and($this->jobs->retryDead(999_999, $this->now))->toBeFalse()
        ->and($this->jobs->discardDead($newer->id))->toBeTrue()
        ->and($this->jobs->find($newer->id))->toBeNull()
        ->and($this->jobs->discardDead($older->id))->toBeFalse();
});

it('deletes finished jobs past their time, and leaves dead and newer ones (15)', function (): void {
    foreach (range(1, 3) as $n) {
        insertJob($this->jobs, $this->now);
    }
    [$old, $recent, $dead] = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'), 'worker-a', 3);
    $this->jobs->markDone($old, $this->now->modify('-20 days'));
    $this->jobs->markDone($recent, $this->now->modify('-1 day'));
    $this->jobs->markDead($dead, 'Gave up', $this->now->modify('-30 days'));

    expect($this->jobs->pruneFinished($this->now->modify('-14 days')))->toBe(1)
        ->and($this->jobs->find($old->id))->toBeNull()
        ->and($this->jobs->find($recent->id))->not->toBeNull()
        ->and($this->jobs->find($dead->id))->not->toBeNull();
});

it('joins the caller\'s transaction instead of opening its own (1, 2)', function (): void {
    expect(Tests\TestCase::pdo()->inTransaction())->toBeTrue()
        ->and($this->jobs->transactional(static fn (): string => 'ran'))->toBe('ran')
        ->and(Tests\TestCase::pdo()->inTransaction())->toBeTrue();
});

it('reads a damaged payload as empty rather than failing the read', function (): void {
    $id = insertJob($this->jobs, $this->now);
    Tests\TestCase::pdo()->prepare('UPDATE jobs SET jobs_payload = ? WHERE jobs_id = ?')->execute(['"just a string"', $id]);

    expect($this->jobs->find($id)->payload)->toBe([]);
});
