<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * What a record needs before it may be published (docs/content.md, 1.): a
 * title, a summary and a body, and whatever its own fields require. Drafts
 * may be missing any of them.
 */
final class PublishBar
{
    /**
     * @param array<string,string|int|null> $fields
     *
     * @return list<string> why not; empty when it may
     */
    public static function problems(RecordType $type, string $title, string $summary, string $body, array $fields): array
    {
        $problems = [];

        foreach (['a title' => trim($title) === '', 'a summary' => trim($summary) === '', 'a body' => $type->body->isEmpty($body)] as $what => $missing) {
            if ($missing) {
                $problems[] = "It needs {$what}.";
            }
        }

        return [...$problems, ...$type->fields->publishProblems($fields)];
    }
}
