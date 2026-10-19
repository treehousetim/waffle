<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Queue;

use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\Job;

/**
 * The queue's test job: a list of slugs, merged as a set when coalesced, the
 * way a site change notice is (docs/queue.md). Registered only in the test
 * container (tests/Support/Overrides/queue.php).
 */
final readonly class RecordingJob implements Job
{
    /** @param list<string> $slugs */
    public function __construct(public array $slugs)
    {
    }

    public static function type(): string
    {
        return 'test-recording';
    }

    public function payload(): array
    {
        return ['slugs' => $this->slugs];
    }

    public static function fromPayload(array $payload): static
    {
        $slugs = $payload['slugs'] ?? null;

        if (!is_array($slugs) || $slugs !== array_values(array_filter($slugs, 'is_string'))) {
            throw InvalidJobPayload::because(self::type(), 'has no list of slugs');
        }

        return new self($slugs);
    }

    public function merge(Job $newer): Job
    {
        if (!$newer instanceof self) {
            return $newer;
        }

        $slugs = array_values(array_unique([...$this->slugs, ...$newer->slugs]));
        sort($slugs);

        return new self($slugs);
    }
}
