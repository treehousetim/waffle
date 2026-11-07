<?php

declare(strict_types=1);

namespace App\Domain\Invitations;

use App\Domain\Auth\Role;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\Message;

/** The email that carries an invitation's link (docs/invitations.md; docs/mail.md, 11., 12.). */
final class InvitationEmail
{
    /** @param string $expiresAt UTC, `Y-m-d H:i:s` */
    public static function message(string $email, Role $role, string $token, string $expiresAt, MailSettings $settings): Message
    {
        $level = strtolower($role->label());
        $article = in_array($level[0] ?? '', ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';

        return Message::of(
            $email,
            "Your invitation to {$settings->appName}",
            "You have been invited to {$settings->appName} as {$article} {$level}.\n\n"
            . "To accept, open this link and choose a password:\n"
            . $settings->link('/invite/' . $token) . "\n\n"
            . 'The link works once, until ' . substr($expiresAt, 0, 10) . ". If you were not expecting this, you can ignore this email: nothing happens unless the link is used.\n",
        );
    }
}
