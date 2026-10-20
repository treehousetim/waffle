<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * A record's working copy as staff send it (docs/content.md), checked. The
 * slug is null when a new record should get one made from its title.
 */
final readonly class Draft
{
    /**
     * @param array<string,string|int|null> $fields the type's own, as stored
     */
    public function __construct(
        public ?string $slug,
        public string $title,
        public string $summary,
        public string $body,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public array $fields,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     *
     * @throws InvalidContent
     */
    public static function parse(RecordType $type, array $input, bool $slugRequired): self
    {
        $slug = $input['slug'] ?? null;

        return new self(
            $slugRequired || (is_string($slug) && trim($slug) !== '') ? Slug::check($slug) : null,
            ContentText::title($input['title'] ?? null),
            ContentText::summary($input['summary'] ?? null),
            Markdown::check($input['body'] ?? null),
            ContentText::seoTitle($input['seo_title'] ?? null),
            ContentText::seoDescription($input['seo_description'] ?? null),
            $type->fields->parse($input),
        );
    }
}
