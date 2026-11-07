<?php

declare(strict_types=1);

namespace App\Domain\Ports;

/**
 * A message the provider received for the domain (docs/mail.md, "Mail sent
 * to the domain"), by its id. Called only from the queue's worker. Throws when
 * it cannot be fetched, so the queue tries again (17.).
 */
interface ReceivedMailPort
{
    /** @return array<string,mixed> the provider's own fields: from, to, subject, text, html, headers */
    public function fetch(string $emailId): array;
}
