<?php

declare(strict_types=1);

use App\Domain\Queue\QueueSettings;
use Tests\Support\E2EArtifact;
use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Fixtures\Queue\RecordingHandler;
use Tests\Support\Fixtures\Queue\RecordingJob;
use Tests\Support\QueueFixtures;

/*
 * The queue end to end (docs/queue.md): two changes coalesce into one job, it
 * fails once and is retried, a job that cannot succeed dies and a person
 * retries it through the API.
 *
 * Ends by writing coverage/e2e/queue-journey.json: facts named by role, every
 * check, and a SHA-256 of both.
 */

it('coalesces, retries, gives up and is rescued, and records what it saw', function (): void {
    QueueFixtures::registerTestJobType();
    $artifact = new E2EArtifact('queue-journey');
    $clock = new FrozenClock();
    $settings = new QueueSettings(maxAttempts: 2, backoffSeconds: 30);
    $flaky = new RecordingHandler(failures: 1);
    $queue = QueueFixtures::queue($flaky, $clock, $settings);
    $worker = QueueFixtures::worker($flaky, $clock, $settings);
    $jobs = QueueFixtures::repository();

    $queue->enqueue(new RecordingJob(['lakeside-cabin']), 'site-notice');
    $queue->enqueue(new RecordingJob(['asheville', 'lakeside-cabin']), 'site-notice');
    $afterEnqueue = $jobs->stats($clock->now());

    $firstRun = $worker->runOnce();
    $afterFailure = $jobs->stats($clock->now());
    $clock->advance('+30 seconds');
    $secondRun = $worker->runOnce();

    $doomed = new RecordingHandler(failures: 99);
    QueueFixtures::queue($doomed, $clock, $settings)->enqueue(new RecordingJob(['broken']));
    $doomedWorker = QueueFixtures::worker($doomed, $clock, $settings);
    $doomedWorker->runOnce();
    $clock->advance('+1 hour');
    $doomedWorker->runOnce();
    $afterDeath = $jobs->stats($clock->now());

    $listed = $this->json($this->asOwner()->get('/api/queue'))['data'];
    $deadId = $listed['dead'][0]['id'];
    $retried = $this->post("/api/queue/jobs/{$deadId}/retry")->getStatusCode();
    $afterRescue = $jobs->stats($clock->now());

    $artifact
        ->fact('pending after two coalesced changes', $afterEnqueue['pending'])
        ->fact('jobs run on the first try', $firstRun)
        ->fact('pending after the first try failed', $afterFailure['pending'])
        ->fact('jobs run on the retry', $secondRun)
        ->fact('slugs the handler saw', $flaky->handled)
        ->fact('dead after the doomed job ran out of attempts', $afterDeath['dead'])
        ->fact('outcomes of the doomed job', array_column($listed['dead'][0]['runs'], 'outcome'))
        ->fact('status of the rescue', $retried)
        ->fact('pending after the rescue', $afterRescue['pending'])
        ->check('two changes with one key make one job', $afterEnqueue['pending'] === 1)
        ->check('a failed job waits to be retried', $afterFailure['pending'] === 1)
        ->check('the retry runs the merged job once', $flaky->handled === [['asheville', 'lakeside-cabin']])
        ->check('a job out of attempts is dead, not retried forever', $afterDeath['dead'] === 1)
        ->check('a person can retry a dead job', $retried === 200 && $afterRescue['pending'] === 1);

    $path = $artifact->write();
    $written = json_decode((string) file_get_contents($path), true);

    expect($written['passed'])->toBeTrue()
        ->and($written['sha256'])->toBe($artifact->digest());
});
