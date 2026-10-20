<?php

declare(strict_types=1);

namespace App\Models\Forms;

use App\Domain\Forms\SubmissionStatus;

/** One form submission (docs/forms.md). */
final readonly class Submission
{
    /** @param array<string,string> $payload */
    public function __construct(
        public int $id,
        public string $form,
        public int $siteId,
        public array $payload,
        public SubmissionStatus $status,
        public string $createdAt,
        public ?string $decidedAt,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'form' => $this->form,
            'site_id' => $this->siteId,
            'fields' => $this->payload,
            'status' => $this->status->value,
            'created_at' => $this->createdAt,
            'decided_at' => $this->decidedAt,
        ];
    }
}
