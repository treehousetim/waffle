<?php

declare(strict_types=1);

namespace App\Domain\Ports;

use App\Domain\Mail\Message;

/**
 * Sends one email (docs/mail.md). Called only from the queue's worker, never
 * from a request (1.). `$key` names this email: the provider drops a repeat of
 * it, so a job run twice sends once (3.). Throws when the email could not be
 * handed over, so the queue tries again (7.).
 */
interface MailPort
{
    public function send(Message $message, string $key): void;
}
