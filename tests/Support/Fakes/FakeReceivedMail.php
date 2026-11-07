<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domain\Ports\ReceivedMailPort;
use RuntimeException;

/** The provider's received mail, as the tests see it: messages by id, or a failure on demand. */
final class FakeReceivedMail implements ReceivedMailPort
{
    /** @var array<string,array<string,mixed>> */
    public array $emails = [];

    public bool $down = false;

    public function fetch(string $emailId): array
    {
        if ($this->down || !isset($this->emails[$emailId])) {
            throw new RuntimeException("The received email {$emailId} could not be fetched.");
        }

        return $this->emails[$emailId];
    }
}
