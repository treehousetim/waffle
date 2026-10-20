<?php

declare(strict_types=1);

namespace App\Domain\Content;

/** A record type with only the fields every record has, such as posts. */
final class NoFields implements RecordFields
{
    public function names(): array
    {
        return [];
    }

    public function parse(array $input): array
    {
        return [];
    }

    public function present(array $stored): array
    {
        return [];
    }

    public function publishProblems(array $stored): array
    {
        return [];
    }

    public function images(array $stored): array
    {
        return [];
    }
}
