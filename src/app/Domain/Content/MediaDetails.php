<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * What staff say about an image (docs/content.md, "Media"; 24.): what it
 * shows, for people who cannot see it (alt text), an optional caption, and
 * whose it is. An image may be saved without alt text, but a record using it
 * cannot be published until it has some, nor a credited one without its
 * credit.
 */
final readonly class MediaDetails
{
    private function __construct(
        public string $alt,
        public ?string $caption,
        public ?string $creditName,
        public ?string $creditUrl,
        public Licence $licence,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     *
     * @throws InvalidContent
     */
    public static function parse(array $input): self
    {
        $licence = Licence::tryFrom(is_string($input['licence'] ?? null) ? $input['licence'] : 'own')
            ?? throw InvalidContent::because('The licence must be own, permission or licensed.');
        $url = ContentText::optionalHttpsUrl($input['credit_url'] ?? null, 'credit link', 500);

        return new self(
            ContentText::optionalLine($input['alt'] ?? null, 'description', 300) ?? '',
            ContentText::optionalLine($input['caption'] ?? null, 'caption', 300),
            ContentText::optionalLine($input['credit_name'] ?? null, 'credit', 150),
            $url,
            $licence,
        );
    }

    /**
     * Why an image is not ready to be published; empty when it is.
     *
     * @return list<string>
     */
    public static function problems(int $id, string $alt, Licence $licence, ?string $creditName): array
    {
        $problems = [];

        if (trim($alt) === '') {
            $problems[] = "Image {$id} needs a description (alt text).";
        }

        if ($licence->needsCredit() && $creditName === null) {
            $problems[] = "Image {$id} needs a credit.";
        }

        return $problems;
    }
}
