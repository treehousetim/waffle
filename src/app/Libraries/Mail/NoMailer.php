<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

use App\Domain\Mail\Message;
use App\Domain\Ports\MailPort;
use Closure;
use RuntimeException;

/**
 * MailPort when there is no `RESEND_API_KEY` (docs/mail.md, 8.). On a
 * developer's machine each email is written to the worker's log instead, links
 * and all, so an invitation or a reset can be followed locally. In production
 * it refuses, so the job fails and the Queue page shows that mail is not going
 * out.
 */
final readonly class NoMailer implements MailPort
{
    /** @param Closure(string):void $write one line of the log */
    public function __construct(private bool $production, private Closure $write)
    {
    }

    public function send(Message $message, string $key): void
    {
        if ($this->production) {
            throw new RuntimeException('No email is sent: set RESEND_API_KEY in Doppler (docs/mail.md).');
        }

        $lines = ['--- email, not sent (no RESEND_API_KEY) ---', 'To: ' . implode(', ', $message->to)];

        if ($message->replyTo !== null) {
            $lines[] = 'Reply-To: ' . $message->replyTo;
        }

        array_push($lines, 'Subject: ' . $message->subject, '', ...explode("\n", rtrim($message->text)));
        $lines[] = '--- end of email ---';

        foreach ($lines as $line) {
            ($this->write)($line);
        }
    }
}
