<?php

declare(strict_types=1);

/**
 * The queue slice (docs/queue.md): QueuePort kept in MariaDB, the worker, and
 * the routes that look after it.
 *
 * Job handlers are not listed here. Each slice that adds a job defines its
 * handler in its own fragment under the key `job:<type>`; container.php
 * gathers those keys into `queue.job_types` for the registry.
 */

use App\Controllers\QueueController;
use App\Controllers\QueueCronController;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\QueuePort;
use App\Domain\Queue\QueueSettings;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Queue\DatabaseQueue;
use App\Libraries\Queue\JobRegistry;
use App\Libraries\Queue\Worker;
use App\Models\JobRepository;
use App\Models\TypeRepository;
use Psr\Container\ContainerInterface;

use function DI\factory;

return [
    QueueSettings::class => factory(static fn (): QueueSettings => new QueueSettings()),

    JobRepository::class => factory(
        static fn (PDO $pdo, TypeRepository $types): JobRepository => new JobRepository($pdo, $types)
    ),

    JobRegistry::class => factory(static function (ContainerInterface $c): JobRegistry {
        /** @var list<string> $types */
        $types = $c->get('queue.job_types');

        return new JobRegistry($c, $types);
    }),

    QueuePort::class => factory(
        static fn (JobRepository $jobs, JobRegistry $registry, ClockPort $clock, QueueSettings $settings): QueuePort
            => new DatabaseQueue($jobs, $registry, $clock, $settings)
    ),

    Worker::class => factory(
        static fn (JobRepository $jobs, JobRegistry $registry, ClockPort $clock, QueueSettings $settings): Worker
            => new Worker($jobs, $registry, $clock, $settings, gethostname() . ':' . getmypid())
    ),

    QueueController::class => factory(
        static fn (JsonResponder $json, JobRepository $jobs, ClockPort $clock): QueueController
            => new QueueController($json, $jobs, $clock)
    ),

    QueueCronController::class => factory(
        static fn (JsonResponder $json, JobRepository $jobs, ClockPort $clock, QueueSettings $settings): QueueCronController
            => new QueueCronController($json, $jobs, $clock, $settings)
    ),
];
