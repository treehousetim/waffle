<?php

declare(strict_types=1);

namespace App\Domain\Content;

use InvalidArgumentException;

/**
 * One kind of record the app publishes (docs/content.md): its table, its two
 * history tables, and its own fields. Declared in code, never read from a
 * request, so every table and column name in the SQL comes from here (15.).
 *
 *   posts             the records: `posts_id`, `posts_slug`, `posts_title`...
 *   posts_revisions   every publish: `posts_revisions_post_id`...
 *   posts_slugs       every slug: `posts_slugs_post_id`...
 */
final readonly class RecordType
{
    public function __construct(
        /** The table, plural: also the name in the API's paths. */
        public string $name,
        /** One record, for the history tables' foreign keys: `post`. */
        public string $singular,
        /** One record, for people: `Post`. */
        public string $label,
        public RecordFields $fields = new NoFields(),
    ) {
        foreach ([$name, $singular, ...$fields->names()] as $identifier) {
            if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $identifier) !== 1) {
                throw new InvalidArgumentException("'{$identifier}' cannot name a table or column.");
            }
        }
    }

    /** A column of the record table: `posts_title`. */
    public function column(string $name): string
    {
        return $this->name . '_' . $name;
    }

    public function revisions(): string
    {
        return $this->name . '_revisions';
    }

    public function slugs(): string
    {
        return $this->name . '_slugs';
    }

    /** The history tables' foreign key to the record: `posts_revisions_post_id`. */
    public function owner(string $history): string
    {
        return $history . '_' . $this->singular . '_id';
    }
}
