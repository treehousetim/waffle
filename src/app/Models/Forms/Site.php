<?php

declare(strict_types=1);

namespace App\Models\Forms;

use App\Domain\Forms\SiteStatus;

/** A site that sends forms (docs/forms.md). Its key is never here: only its hash. */
final readonly class Site
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $origin,
        public ?string $keyHash,
        public ?string $keyIssuedAt,
        public SiteStatus $status,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'origin' => $this->origin,
            'status' => $this->status->value,
            'has_key' => $this->keyHash !== null,
            'key_issued_at' => $this->keyIssuedAt,
        ];
    }
}
