<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

use App\Domain\Auth\Permission;
use App\Domain\Auth\Permissions;
use App\Domain\Auth\Role;
use App\Domain\Mail\InboundEmail;
use App\Domain\Mail\InboundMail;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\SendMail;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\QueuePort;
use App\Domain\Ports\ReceivedMailPort;
use App\Domain\Queue\Job;
use App\Domain\Queue\JobHandler;
use App\Models\Forms\SubmissionRepository;
use App\Models\UserRepository;
use InvalidArgumentException;

/**
 * Fetches a message sent to the domain, files it in the Inbox as an Email
 * submission, and tells whoever may read the Inbox (docs/mail.md). Filed once,
 * however often the job runs (14.); a message that cannot be fetched fails,
 * so the queue tries again (17.). One with no usable sender is not filed.
 */
final readonly class InboundMailHandler implements JobHandler
{
    public function __construct(
        private ReceivedMailPort $received,
        private SubmissionRepository $submissions,
        private UserRepository $users,
        private QueuePort $queue,
        private MailSettings $settings,
        private ClockPort $clock,
    ) {
    }

    public function jobClass(): string
    {
        return InboundMail::class;
    }

    public function handle(Job $job): void
    {
        assert($job instanceof InboundMail);

        if ($this->submissions->hasEmail($job->emailId)) {
            return;
        }

        try {
            $fields = InboundEmail::fields($this->received->fetch($job->emailId));
        } catch (InvalidArgumentException) {
            return;
        }

        $readers = $this->users->addressesWithAnyRole(array_values(array_filter(
            Role::cases(),
            static fn (Role $role): bool => in_array(Permission::FormsManage, Permissions::for($role), true),
        )));

        $this->submissions->transactional(function () use ($job, $fields, $readers): void {
            $this->submissions->insert('email', null, [...$fields, 'email_id' => $job->emailId], hash('sha256', 'email|' . $fields['email']), $this->clock->now());

            foreach ($readers as $reader) {
                $this->queue->enqueue(SendMail::of(InboundEmail::notice($fields, $reader, $this->settings)));
            }
        });
    }
}
