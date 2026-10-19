<?php

declare(strict_types=1);

namespace App\Libraries\Queue;

use App\Domain\Queue\Job;
use App\Domain\Queue\JobHandler;
use Psr\Container\ContainerInterface;

/**
 * Which handler runs which job (docs/queue.md).
 *
 * Handlers are container definitions keyed `job:<type>`, one per job, in the
 * slice that owns the job. container.php lists those keys once, so this
 * registry knows every type without any slice editing a shared file. Handlers
 * are resolved only when a job of their type runs.
 */
final readonly class JobRegistry
{
    /**
     * @param list<string> $types every registered job type
     */
    public function __construct(
        private ContainerInterface $container,
        private array $types,
    ) {
    }

    /** @return list<string> */
    public function types(): array
    {
        $types = $this->types;
        sort($types);

        return $types;
    }

    public function has(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /**
     * @throws UnknownJobType when nothing handles $type, or what is registered
     *                        under it is not a handler for a job of that type
     */
    public function handler(string $type): JobHandler
    {
        if (!$this->has($type)) {
            throw UnknownJobType::named($type);
        }

        $handler = $this->container->get('job:' . $type);

        if (!$handler instanceof JobHandler || !is_subclass_of($handler->jobClass(), Job::class)
            || $handler->jobClass()::type() !== $type) {
            throw UnknownJobType::named($type);
        }

        return $handler;
    }
}
