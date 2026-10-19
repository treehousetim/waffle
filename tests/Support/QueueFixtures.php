<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ports\ClockPort;
use App\Domain\Queue\QueueSettings;
use App\Libraries\Queue\DatabaseQueue;
use App\Libraries\Queue\JobRegistry;
use App\Libraries\Queue\Worker;
use App\Models\JobRepository;
use App\Models\TypeRepository;
use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Fixtures\Queue\RecordingHandler;
use Tests\TestCase;

/**
 * Builds the real queue around the test job, for the queue's own tests.
 *
 * Every piece shares one FrozenClock, so a test moves time for all of them.
 */
final class QueueFixtures
{
    /** Inserts the test job's `types` row, and makes TypeRepository see it. */
    public static function registerTestJobType(): void
    {
        $pdo = TestCase::pdo();
        $exists = $pdo->query("SELECT COUNT(*) FROM types WHERE types_group = 'job' AND types_name = 'test-recording'")->fetchColumn();

        if ((int) $exists === 0) {
            Factory::insert('types', [
                'types_group' => 'job',
                'types_name' => 'test-recording',
                'types_label' => 'Test job',
                'types_sort' => 10,
            ]);
        }

        self::types()->forget();
    }

    public static function types(): TypeRepository
    {
        /** @var TypeRepository $types */
        $types = TestCase::container()->get(TypeRepository::class);

        return $types;
    }

    public static function repository(?\PDO $pdo = null): JobRepository
    {
        return new JobRepository($pdo ?? TestCase::pdo(), self::types());
    }

    public static function registry(RecordingHandler $handler): JobRegistry
    {
        $container = new \DI\Container(['job:test-recording' => $handler]);

        return new JobRegistry($container, ['test-recording']);
    }

    public static function queue(RecordingHandler $handler, ClockPort $clock, ?QueueSettings $settings = null): DatabaseQueue
    {
        return new DatabaseQueue(self::repository(), self::registry($handler), $clock, $settings ?? new QueueSettings());
    }

    public static function worker(
        RecordingHandler $handler,
        FrozenClock $clock,
        ?QueueSettings $settings = null,
        string $name = 'worker-a',
    ): Worker {
        return new Worker(
            self::repository(),
            self::registry($handler),
            $clock,
            $settings ?? new QueueSettings(),
            $name,
            static fn (int $max): int => 0,
        );
    }
}
