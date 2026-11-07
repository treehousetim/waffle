<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Domain\Auth\PasswordResetEmail;
use App\Domain\Auth\PasswordResetMail;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Mail\MailSettings;
use App\Domain\Ports\MailPort;
use App\Domain\Queue\Job;
use App\Domain\Queue\JobHandler;

/**
 * Emails a password reset link (docs/password-reset.md): the link is issued
 * as it is sent and kept only once the email has gone (9.). A request closed
 * or expired before the email went is sent nothing.
 */
final readonly class PasswordResetMailHandler implements JobHandler
{
    public function __construct(
        private PasswordResets $resets,
        private MailPort $mailer,
        private MailSettings $settings,
    ) {
    }

    public function jobClass(): string
    {
        return PasswordResetMail::class;
    }

    public function handle(Job $job): void
    {
        assert($job instanceof PasswordResetMail);

        try {
            $this->resets->issueLink($job->resetId, function (string $email, string $token) use ($job): void {
                $this->mailer->send(
                    PasswordResetEmail::link($email, $token, $this->settings),
                    'reset-' . $job->resetId . '-' . substr((string) InvitationToken::hash($token), 0, 16),
                );
            });
        } catch (ResetNotFound) {
            // Closed or expired before the email went: nothing to send.
        }
    }
}
