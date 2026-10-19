<?php

declare(strict_types=1);

use App\Domain\Queue\JobStatus;
use App\Domain\Queue\QueueSettings;
use App\Libraries\Queue\Worker;
use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Fixtures\Queue\RecordingHandler;
use Tests\Support\Fixtures\Queue\RecordingJob;
use Tests\Support\QueueFixtures;
use Tests\TestCase;

/*
 * The worker (docs/queue.md): claim, run, record. The numbers are the failure
 * list's.
 */

beforeEach(function (): void {
    QueueFixtures::registerTestJobType();
    $this->clock = new FrozenClock();
    $this->handler = new RecordingHandler();
    $this->settings = new QueueSettings(maxAttempts: 3, backoffSeconds: 30, batchSize: 5);
    $this->queue = QueueFixtures::queue($this->handler, $this->clock, $this->settings);
    $this->worker = QueueFixtures::worker($this->handler, $this->clock, $this->settings);
    $this->jobs = QueueFixtures::repository();
});

function onlyJob(): App\Models\QueuedJob
{
    $id = (int) TestCase::pdo()->query('SELECT MAX(jobs_id) FROM jobs')->fetchColumn();

    return QueueFixtures::repository()->find($id);
}

it('runs a due job and records it done', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']));

    expect($this->worker->runOnce())->toBe(1)
        ->and($this->handler->handled)->toBe([['a']])
        ->and(onlyJob()->status)->toBe(JobStatus::Done)
        ->and($this->jobs->runs(onlyJob()->id)[0])->toMatchArray(['attempt' => 1, 'outcome' => 'done', 'worker' => 'worker-a']);
});

it('leaves a job that is not yet due', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']), runAfter: $this->clock->now()->modify('+1 minute'));

    expect($this->worker->runOnce())->toBe(0)
        ->and($this->handler->handled)->toBe([]);
});

it('retries a failed job after a growing delay (7)', function (): void {
    $this->handler->failures = 2;
    $this->queue->enqueue(new RecordingJob(['a']));

    $this->worker->runOnce();
    $first = onlyJob();

    expect($first->status)->toBe(JobStatus::Pending)
        ->and($first->runAfter)->toBe('2026-01-01 12:00:30')
        ->and($first->lastError)->toBe('RuntimeException: The recording handler was told to fail.');

    $this->clock->advance('+30 seconds');
    $this->worker->runOnce();

    expect(onlyJob()->runAfter)->toBe('2026-01-01 12:01:30');

    $this->clock->advance('+60 seconds');
    $this->worker->runOnce();

    expect(onlyJob()->status)->toBe(JobStatus::Done)
        ->and(array_column($this->jobs->runs(onlyJob()->id), 'outcome'))->toBe(['failed', 'failed', 'done']);
});

it('gives up after its last attempt instead of retrying forever (6)', function (): void {
    $this->handler->failures = 10;
    $this->queue->enqueue(new RecordingJob(['a']));

    foreach (range(1, 5) as $attempt) {
        $this->worker->runOnce();
        $this->clock->advance('+1 hour');
    }

    expect(onlyJob()->status)->toBe(JobStatus::Dead)
        ->and(onlyJob()->attempts)->toBe(3)
        ->and(array_column($this->jobs->runs(onlyJob()->id), 'outcome'))->toBe(['failed', 'failed', 'dead']);
});

it('marks a job dead at once when its payload cannot be read (10)', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']));
    TestCase::pdo()->exec('UPDATE jobs SET jobs_payload = \'{"old":"shape"}\'');

    $this->worker->runOnce();

    expect(onlyJob()->status)->toBe(JobStatus::Dead)
        ->and(onlyJob()->lastError)->toBe("The test-recording job's payload has no list of slugs.")
        ->and($this->handler->handled)->toBe([]);
});

it('marks a job dead at once when nothing handles its type any more (11)', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']));
    $worker = new Worker(
        $this->jobs,
        new App\Libraries\Queue\JobRegistry(new DI\Container([]), []),
        $this->clock,
        $this->settings,
        'worker-a',
    );

    $worker->runOnce();

    expect(onlyJob()->status)->toBe(JobStatus::Dead)
        ->and(onlyJob()->lastError)->toContain("No handler is registered for the 'test-recording' job");
});

it('finishes the job in hand on a stop, and gives back the ones it had not started (14)', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']));
    $this->queue->enqueue(new RecordingJob(['b']));
    $handler = new class extends Tests\Support\Fixtures\Queue\RecordingHandler {
        public ?Worker $worker = null;

        public function handle(App\Domain\Queue\Job $job): void
        {
            parent::handle($job);
            $this->worker?->stop();
        }
    };
    $worker = QueueFixtures::worker($handler, $this->clock, $this->settings);
    $handler->worker = $worker;

    expect($worker->runOnce())->toBe(1)
        ->and($worker->stopping())->toBeTrue()
        ->and($handler->handled)->toBe([['a']])
        ->and($this->jobs->stats($this->clock->now()))->toMatchArray(['pending' => 1, 'running' => 0])
        ->and($worker->runOnce())->toBe(0);
});

it('runs until it has run its share, then exits for a fresh process (13)', function (): void {
    foreach (range(1, 7) as $n) {
        $this->queue->enqueue(new RecordingJob([(string) $n]));
    }

    expect($this->worker->run(maxJobs: 5, sleep: static fn () => null))->toBe(5);
});

it('runs until it uses its memory, then exits for a fresh process (13)', function (): void {
    $this->queue->enqueue(new RecordingJob(['a']));

    expect($this->worker->run(maxMemoryBytes: 10, memory: static fn (): int => 100))->toBe(1);
});

it('waits between empty batches a second at a time, and notices a stop while waiting (14)', function (): void {
    $slept = [];
    $worker = $this->worker;
    $sleep = static function (int $seconds) use (&$slept, $worker): void {
        $slept[] = $seconds;

        if (count($slept) === 3) {
            $worker->stop();
        }
    };

    expect($worker->run(idleSeconds: 5, sleep: $sleep))->toBe(0)
        ->and($slept)->toBe([1, 1, 1]);
});

it('stops at once when stopped before it starts, using the real sleep and memory', function (): void {
    $this->worker->stop();

    expect($this->worker->run())->toBe(0)
        ->and(Worker::memoryInUse())->toBeGreaterThan(0);

    $before = microtime(true);
    Worker::sleepFor(0);

    expect(microtime(true) - $before)->toBeLessThan(1.0);
});

it('keeps a long error short for the people reading it', function (): void {
    $handler = new class extends Tests\Support\Fixtures\Queue\RecordingHandler {
        public function handle(App\Domain\Queue\Job $job): void
        {
            throw new RuntimeException(str_repeat('x', 3000));
        }
    };
    $this->queue->enqueue(new RecordingJob(['a']));

    QueueFixtures::worker($handler, $this->clock, $this->settings)->runOnce();

    expect(mb_strlen((string) onlyJob()->lastError))->toBe(2001)
        ->and(str_ends_with((string) onlyJob()->lastError, '…'))->toBeTrue();
});

it('draws its jitter at random when given none (7)', function (): void {
    $this->handler->failures = 1;
    $this->queue->enqueue(new RecordingJob(['a']));
    $worker = new Worker($this->jobs, QueueFixtures::registry($this->handler), $this->clock, $this->settings, 'worker-a');

    $worker->runOnce();
    $delay = (new DateTimeImmutable(onlyJob()->runAfter . ' UTC'))->getTimestamp() - $this->clock->now()->getTimestamp();

    expect($delay)->toBeGreaterThanOrEqual(30)->toBeLessThanOrEqual(60);
});
