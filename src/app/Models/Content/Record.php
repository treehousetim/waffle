<?php

declare(strict_types=1);

namespace App\Models\Content;

use App\Domain\Content\PublishStatus;
use App\Domain\Content\RecordType;

/**
 * A record's working copy (docs/content.md): the row in its type's table.
 * What the site gets is its latest revision, not this.
 */
final readonly class Record
{
    /**
     * @param array<string,string|int|null> $fields the type's own, as stored
     */
    public function __construct(
        public int $id,
        public string $slug,
        public PublishStatus $status,
        public string $title,
        public string $summary,
        public string $body,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public array $fields,
        public ?string $publishedAt,
        public ?string $retiredAt,
        public string $contentChangedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * What a revision keeps: everything the site shows, own fields as stored.
     *
     * @return array{slug:string,title:string,summary:string,body:string,seo_title:?string,seo_description:?string,fields:array<string,string|int|null>}
     */
    public function content(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'fields' => $this->fields,
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(RecordType $type): array
    {
        return [
            'id' => $this->id,
            'type' => $type->name,
            'status' => $this->status->value,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            ...$type->fields->present($this->fields),
            'published_at' => $this->publishedAt,
            'retired_at' => $this->retiredAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
