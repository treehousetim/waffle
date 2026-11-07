<?php

declare(strict_types=1);

namespace App\Libraries\Invitations;

use App\Domain\Invitations\InvitationEmail;
use App\Domain\Invitations\InvitationMail;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Mail\MailSettings;
use App\Domain\Ports\MailPort;
use App\Domain\Queue\Job;
use App\Domain\Queue\JobHandler;
use App\Models\Invitation;

/**
 * Emails an invitation (docs/invitations.md): issues a new link and sends it,
 * and the link is kept only once the email has gone, so a send that fails
 * leaves the link the invitation had (docs/mail.md, 7.). An invitation
 * revoked, used or expired before the email went is sent nothing.
 */
final readonly class InvitationMailHandler implements JobHandler
{
    public function __construct(
        private Invitations $invitations,
        private MailPort $mailer,
        private MailSettings $settings,
    ) {
    }

    public function jobClass(): string
    {
        return InvitationMail::class;
    }

    public function handle(Job $job): void
    {
        assert($job instanceof InvitationMail);

        try {
            $this->invitations->issueLink($job->invitationId, function (Invitation $invitation, string $token): void {
                $this->mailer->send(
                    InvitationEmail::message($invitation->email, $invitation->role, $token, $invitation->expiresAt, $this->settings),
                    'invitation-' . $invitation->id . '-' . substr((string) InvitationToken::hash($token), 0, 16),
                );
            });
        } catch (InvitationNotFound) {
            // Revoked, used or expired before the email went: nothing to send.
        }
    }
}
