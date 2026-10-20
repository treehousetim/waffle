<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * The record types this app publishes (docs/content.md), by name. A request
 * names a type; only a name found here reaches the SQL (15.).
 */
final readonly class RecordTypes
{
    /** @var array<string,RecordType> */
    private array $types;

    public function __construct(RecordType ...$types)
    {
        $byName = [];

        foreach ($types as $type) {
            $byName[$type->name] = $type;
        }

        $this->types = $byName;
    }

    public function find(string $name): ?RecordType
    {
        return $this->types[$name] ?? null;
    }

    /** @return list<RecordType> */
    public function all(): array
    {
        return array_values($this->types);
    }
}
