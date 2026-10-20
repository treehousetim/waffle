<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\Job;

/**
 * What the site must rebuild (docs/content.md, "Change notices"): by record
 * type, the slugs whose pages changed. Never "rebuild everything" (AGENTS.md
 * §4); the site works out which pages those slugs touch. Coalesced: notices
 * waiting together merge into one, slugs unioned.
 */
final readonly class ChangeNotice implements Job
{
    /** @param array<string,list<string>> $changes record type => slugs, both sorted */
    private function __construct(public array $changes)
    {
    }

    /** @param list<string> $slugs */
    public static function of(string $type, array $slugs): self
    {
        return self::normalised([$type => $slugs]);
    }

    public static function type(): string
    {
        return 'site-change-notice';
    }

    public function payload(): array
    {
        return ['changes' => $this->changes];
    }

    public static function fromPayload(array $payload): static
    {
        $changes = $payload['changes'] ?? null;

        if (!is_array($changes) || $changes === []) {
            throw InvalidJobPayload::because(self::type(), 'has no changes');
        }

        foreach ($changes as $type => $slugs) {
            if (!is_string($type) || !is_array($slugs) || $slugs === [] || $slugs !== array_values(array_filter($slugs, 'is_string'))) {
                throw InvalidJobPayload::because(self::type(), 'has changes that are not lists of slugs by record type');
            }
        }

        /** @var array<string,list<string>> $changes */
        return self::normalised($changes);
    }

    public function merge(Job $newer): Job
    {
        if (!$newer instanceof self) {
            return $newer;
        }

        return self::normalised(array_merge_recursive($this->changes, $newer->changes));
    }

    /** @param array<string,list<string>> $changes */
    private static function normalised(array $changes): self
    {
        ksort($changes);

        return new self(array_map(static function (array $slugs): array {
            $slugs = array_values(array_unique($slugs));
            sort($slugs);

            return $slugs;
        }, $changes));
    }
}
