<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

use App\Domain\Ports\ReceivedMailPort;
use RuntimeException;

/**
 * ReceivedMailPort when there is no `RESEND_RECEIVING_KEY` (docs/mail.md): the
 * message cannot be fetched, so the job fails and the Queue page shows why
 * (17.), and it is fetched once the key is set and the job retried.
 */
final readonly class NoReceivedMail implements ReceivedMailPort
{
    public function fetch(string $emailId): array
    {
        throw new RuntimeException('Mail sent to the domain cannot be fetched: set RESEND_RECEIVING_KEY in Doppler (docs/mail.md).');
    }
}
