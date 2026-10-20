<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auth\Role;
use App\Domain\Invitations\InvitationStatus;

/** One invitation (docs/invitations.md). Its token is never here: only its hash is stored. */
final readonly class Invitation
{
    public function __construct(
        public int $id,
        public string $email,
        public Role $role,
        public InvitationStatus $status,
        public int $createdBy,
        public string $createdAt,
        public string $expiresAt,
        public ?string $decidedAt,
        public ?int $userId,
    ) {
    }

    /** Whether its link may still be used at this moment (UTC, "Y-m-d H:i:s"). */
    public function opensAt(string $now): bool
    {
        return $this->status === InvitationStatus::Pending && $this->expiresAt > $now;
    }

    /** @return array<string,mixed> */
    public function toArray(string $now): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'level' => $this->role->value,
            'level_label' => $this->role->label(),
            'status' => $this->status === InvitationStatus::Pending && !$this->opensAt($now) ? 'expired' : $this->status->value,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'decided_at' => $this->decidedAt,
        ];
    }
}
