<?php

declare(strict_types=1);

namespace App\Domain\Queue;

/**
 * One unit of work to run later, outside the request that asked for it
 * (docs/queue.md).
 *
 * A job is a value object: its type names it, its payload is plain JSON built
 * from typed fields (never PHP serialize()), and fromPayload() rebuilds it,
 * refusing anything it does not recognise. The work itself is its handler's
 * (JobHandler), so a job can be enqueued without the code that runs it.
 *
 * Every job's handler is idempotent: the queue runs a job at least once, and
 * sometimes twice.
 */
interface Job
{
    /** The job's name: its `types` row in group `job`, e.g. "site-change-notice". */
    public static function type(): string;

    /**
     * What the job needs to run, as JSON-safe data. Only what it needs: no
     * secrets, and no more personal data than the work requires (19.).
     *
     * @return array<string,mixed>
     */
    public function payload(): array;

    /**
     * Rebuilds the job from a stored payload.
     *
     * @param array<string,mixed> $payload
     *
     * @throws InvalidJobPayload when the payload is not one this job writes (10.)
     */
    public static function fromPayload(array $payload): static;

    /**
     * Folds a newer job of the same type and unique key into this one, for
     * coalescing (docs/queue.md, "Coalescing"). Most jobs return the newer
     * one; a change notice returns the union of both jobs' slugs.
     */
    public function merge(self $newer): self;
}
