<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

use App\Domain\Mail\SendMail;
use App\Domain\Ports\MailPort;
use App\Domain\Queue\Job;
use App\Domain\Queue\JobHandler;

/** Sends a queued email, under the key it was queued with (docs/mail.md, 3.). */
final readonly class SendMailHandler implements JobHandler
{
    public function __construct(private MailPort $mailer)
    {
    }

    public function jobClass(): string
    {
        return SendMail::class;
    }

    public function handle(Job $job): void
    {
        assert($job instanceof SendMail);

        $this->mailer->send($job->message, $job->key);
    }
}
