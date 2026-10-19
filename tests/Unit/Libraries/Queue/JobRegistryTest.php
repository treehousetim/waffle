<?php

declare(strict_types=1);

use App\Domain\Queue\Job;
use App\Domain\Queue\JobHandler;
use App\Libraries\Queue\JobRegistry;
use App\Libraries\Queue\UnknownJobType;
use DI\Container;
use Tests\Support\Fixtures\Queue\RecordingHandler;

/* Which handler runs which job (docs/queue.md, failure 11). */

it('lists its types in order and finds a handler by type', function (): void {
    $handler = new RecordingHandler();
    $registry = new JobRegistry(new Container(['job:test-recording' => $handler]), ['zeta', 'test-recording']);

    expect($registry->types())->toBe(['test-recording', 'zeta'])
        ->and($registry->has('test-recording'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and($registry->handler('test-recording'))->toBe($handler);
});

it('refuses a type it was not given (11)', function (): void {
    $registry = new JobRegistry(new Container([]), []);

    expect(fn () => $registry->handler('missing'))->toThrow(UnknownJobType::class, "'missing'");
});

it('refuses something registered under a type that is not that type\'s handler (11)', function (mixed $registered): void {
    $registry = new JobRegistry(new Container(['job:test-recording' => $registered]), ['test-recording']);

    expect(fn () => $registry->handler('test-recording'))->toThrow(UnknownJobType::class);
})->with([
    'not a handler' => [new stdClass()],
    'a handler for a class that is not a job' => [new class implements JobHandler {
        public function jobClass(): string
        {
            return stdClass::class;
        }

        public function handle(Job $job): void
        {
        }
    }],
    'a handler for another job' => [new class implements JobHandler {
        public function jobClass(): string
        {
            return OtherJob::class;
        }

        public function handle(Job $job): void
        {
        }
    }],
]);

final readonly class OtherJob implements Job
{
    public static function type(): string
    {
        return 'other';
    }

    public function payload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload): static
    {
        return new self();
    }

    public function merge(Job $newer): Job
    {
        return $newer;
    }
}
