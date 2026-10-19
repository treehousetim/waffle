<?php

declare(strict_types=1);

use Tests\Support\Fixtures\Queue\RecordingHandler;
use Tests\Support\Fixtures\Queue\RecordingJob;
use Tests\Support\QueueFixtures;

/*
 * The queue's routes (docs/queue.md): failed jobs for people with
 * `queue-manage` (20.), and the cron check (15., 16.). AccessGridTest covers
 * 401 and 403 for every level.
 */

beforeEach(function (): void {
    QueueFixtures::registerTestJobType();
    // The container's clock: the same FrozenClock the routes read.
    $this->clock = Tests\TestCase::container()->get(App\Domain\Ports\ClockPort::class);
    $this->jobs = QueueFixtures::repository();
});

function deadJob(object $test): int
{
    $handler = new RecordingHandler(failures: 99);
    QueueFixtures::queue($handler, $test->clock, new App\Domain\Queue\QueueSettings(maxAttempts: 1))
        ->enqueue(new RecordingJob(['a']));
    QueueFixtures::worker($handler, $test->clock, new App\Domain\Queue\QueueSettings(maxAttempts: 1))->runOnce();

    return (int) Tests\TestCase::pdo()->query('SELECT MAX(jobs_id) FROM jobs')->fetchColumn();
}

it('shows what is waiting and every failed job with its runs (20)', function (): void {
    $id = deadJob($this);

    $response = $this->asOwner()->get('/api/queue');
    $data = $this->json($response)['data'];

    expect($response->getStatusCode())->toBe(200)
        ->and($data['stats'])->toBe(['pending' => 0, 'running' => 0, 'dead' => 1, 'oldest_due_seconds' => 0])
        ->and($data['dead'][0])->toMatchArray([
            'id' => $id,
            'type' => 'test-recording',
            'attempts' => 1,
            'error' => 'RuntimeException: The recording handler was told to fail.',
            'created_at' => '2026-01-01 12:00:00',
            'failed_at' => '2026-01-01 12:00:00',
        ])
        ->and($data['dead'][0]['runs'][0]['outcome'])->toBe('dead');
});

it('retries a failed job, once (20)', function (): void {
    $id = deadJob($this);

    $first = $this->asAdministrator()->post("/api/queue/jobs/{$id}/retry");
    $again = $this->post("/api/queue/jobs/{$id}/retry");

    expect($first->getStatusCode())->toBe(200)
        ->and($this->json($first)['data'])->toBe(['retried' => true])
        ->and($this->jobs->find($id)->status)->toBe(App\Domain\Queue\JobStatus::Pending)
        ->and($again->getStatusCode())->toBe(404)
        ->and($this->json($again)['error'])->toBe('There is no failed job with that id.');
});

it('discards a failed job, once (20)', function (): void {
    $id = deadJob($this);

    $first = $this->asOwner()->delete("/api/queue/jobs/{$id}");

    expect($first->getStatusCode())->toBe(200)
        ->and($this->json($first)['data'])->toBe(['discarded' => true])
        ->and($this->jobs->find($id))->toBeNull()
        ->and($this->delete("/api/queue/jobs/{$id}")->getStatusCode())->toBe(404);
});

it('answers 404 to an id that is not a number', function (): void {
    expect($this->asOwner()->post('/api/queue/jobs/abc/retry')->getStatusCode())->toBe(404)
        ->and($this->delete('/api/queue/jobs/0')->getStatusCode())->toBe(404);
});

it('deletes jobs finished past their keeping time when cron calls (15)', function (): void {
    $id = $this->jobs->insert('test-recording', ['slugs' => []], null, $this->clock->now(), 3, $this->clock->now());
    [$job] = $this->jobs->claim($this->clock->now(), $this->clock->now()->modify('+5 minutes'), 'worker-a', 1);
    $this->jobs->markDone($job, $this->clock->now()->modify('-15 days'));

    $response = $this->withCronSecret()->get('/cron/queue');

    expect($response->getStatusCode())->toBe(200)
        ->and($this->json($response)['data'])->toBe([
            'stats' => ['pending' => 0, 'running' => 0, 'dead' => 0, 'oldest_due_seconds' => 0],
            'pruned' => 1,
        ])
        ->and($this->jobs->find($id))->toBeNull();
});

it('answers 503 when a due job has waited past the stall threshold: the worker has stopped (16)', function (): void {
    $this->jobs->insert('test-recording', ['slugs' => []], null, $this->clock->now()->modify('-16 minutes'), 3, $this->clock->now());

    $response = $this->withCronSecret()->get('/cron/queue');

    expect($response->getStatusCode())->toBe(503)
        ->and($this->json($response)['error'])->toBe('A due job has waited 960s, longer than 900s: is the worker running?');
});

it('refuses cron without the secret', function (): void {
    expect($this->get('/cron/queue')->getStatusCode())->toBe(403);
});
