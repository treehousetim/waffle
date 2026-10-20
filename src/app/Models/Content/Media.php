<?php

declare(strict_types=1);

namespace App\Models\Content;

use App\Domain\Content\Licence;
use App\Domain\Content\MediaDetails;

/** One image in the media library (docs/content.md, "Media"). */
final readonly class Media
{
    public function __construct(
        public int $id,
        public string $storageKey,
        public string $sha256,
        public int $width,
        public int $height,
        public int $bytes,
        public string $alt,
        public ?string $caption,
        public ?string $creditName,
        public ?string $creditUrl,
        public Licence $licence,
        public ?string $publicAt,
        public string $createdAt,
    ) {
    }

    /** @return list<string> why it cannot be published yet */
    public function problems(): array
    {
        return MediaDetails::problems($this->id, $this->alt, $this->licence, $this->creditName);
    }

    /** @return array<string,mixed> as staff see it */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'alt' => $this->alt,
            'caption' => $this->caption,
            'credit_name' => $this->creditName,
            'credit_url' => $this->creditUrl,
            'licence' => $this->licence->value,
            'public' => $this->publicAt !== null,
            'problems' => $this->problems(),
            'created_at' => $this->createdAt,
        ];
    }

    /** @return array<string,mixed> as the site gets it: where to fetch it, its size, and what to say about it */
    public function forSite(): array
    {
        return [
            'id' => $this->id,
            'path' => "/api/site/media/{$this->id}.jpg",
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'caption' => $this->caption,
            'credit_name' => $this->creditName,
            'credit_url' => $this->creditUrl,
        ];
    }
}
