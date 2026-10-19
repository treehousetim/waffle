<?php

declare(strict_types=1);

use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Fixtures\Queue\RecordingHandler;
use Tests\Support\Fixtures\Queue\RecordingJob;
use Tests\Support\QueueFixtures;
use Tests\TestCase;

/*
 * The queue against real commits, which the rolled-back unit tests cannot
 * make (docs/queue.md, failures 1-3). Not transaction-wrapped, so every row
 * made here is deleted here.
 */

beforeEach(function (): void {
    QueueFixtures::registerTestJobType();
    $this->clock = new FrozenClock();
    $this->queue = QueueFixtures::queue(new RecordingHandler(), $this->clock);
    $this->jobs = QueueFixtures::repository();
});

afterEach(function (): void {
    $pdo = TestCase::pdo();

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $pdo->exec('DELETE FROM jobs');
    $pdo->exec("DELETE FROM types WHERE types_group = 'job' AND types_name = 'test-recording'");
    QueueFixtures::types()->forget();
});

function jobCount(): int
{
    return (int) TestCase::pdo()->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
}

function secondConnection(): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], (int) $_ENV['DB_PORT'], $_ENV['DB_NAME']),
        (string) $_ENV['DB_USER'],
        (string) $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
}

it('forgets a job when the change that asked for it rolls back (1)', function (): void {
    TestCase::pdo()->beginTransaction();
    $this->queue->enqueue(new RecordingJob(['a']), 'site');
    TestCase::pdo()->rollBack();

    expect(jobCount())->toBe(0);
});

it('keeps a job when the change that asked for it commits (2)', function (): void {
    TestCase::pdo()->beginTransaction();
    $this->queue->enqueue(new RecordingJob(['a']), 'site');
    TestCase::pdo()->commit();

    expect(jobCount())->toBe(1);
});

it('commits its own transaction when the caller has none', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']), 'site');
    $this->queue->enqueue(new RecordingJob(['b']), 'site');

    expect(jobCount())->toBe(1)
        ->and(TestCase::pdo()->inTransaction())->toBeFalse();
});

it('rolls its own transaction back when the work inside it fails', function (): void {
    expect(fn () => $this->jobs->transactional(function (): void {
        $this->jobs->insert('test-recording', ['slugs' => []], null, $this->clock->now(), 3, $this->clock->now());

        throw new RuntimeException('half way');
    }))->toThrow(RuntimeException::class, 'half way');

    expect(jobCount())->toBe(0)
        ->and(TestCase::pdo()->inTransaction())->toBeFalse();
});

it('never gives two workers the same job (3)', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']));
    $this->queue->enqueue(new RecordingJob(['b']));
    $other = QueueFixtures::repository(secondConnection());
    $now = $this->clock->now();

    // Worker A holds its claim's row lock in an open transaction, at the
    // isolation a claim runs at, while worker B claims: SKIP LOCKED passes
    // A's job over, and no gap lock of A's holds B up.
    TestCase::pdo()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    TestCase::pdo()->beginTransaction();
    $first = $this->jobs->claim($now, $now->modify('+5 minutes'), 'worker-a', 1);
    $second = $other->claim($now, $now->modify('+5 minutes'), 'worker-b', 2);
    TestCase::pdo()->commit();

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($first[0]->id)->not->toBe($second[0]->id);
});
