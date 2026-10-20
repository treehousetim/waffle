<?php

declare(strict_types=1);

namespace App\Models\Content;

/** One publish of a record, kept for good (docs/content.md). */
final readonly class Revision
{
    /**
     * @param array{slug:string,title:string,summary:string,body:string,seo_title:?string,seo_description:?string,fields:array<string,string|int|null>} $content
     */
    public function __construct(
        public int $id,
        public int $recordId,
        public array $content,
        public int $publishedBy,
        public string $publishedAt,
    ) {
    }

    /** @return array<string,mixed> what the revision list shows */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->content['title'],
            'slug' => $this->content['slug'],
            'published_by' => $this->publishedBy,
            'published_at' => $this->publishedAt,
        ];
    }
}
