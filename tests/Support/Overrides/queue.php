<?php

declare(strict_types=1);

/**
 * The queue slice, as the test container binds it (docs/queue.md).
 *
 * Code that enqueues gets FakeQueue, which records instead of storing. The
 * test job (RecordingJob) is registered so the real queue and worker can be
 * exercised; its `types` row is inserted by QueueFixtures, inside each test's
 * transaction.
 */

use App\Domain\Ports\QueuePort;
use Tests\Support\Fakes\FakeQueue;
use Tests\Support\Fixtures\Queue\RecordingHandler;

use function DI\factory;

return [
    QueuePort::class => factory(static fn (): QueuePort => new FakeQueue()),
    'job:test-recording' => factory(static fn (): RecordingHandler => new RecordingHandler()),
    'queue.job_types' => ['test-recording'],
];
