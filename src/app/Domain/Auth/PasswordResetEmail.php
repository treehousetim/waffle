<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Mail\MailSettings;
use App\Domain\Mail\Message;

/** The two emails of a password reset (docs/password-reset.md; docs/mail.md, 11., 12.). */
final class PasswordResetEmail
{
    public static function link(string $email, string $token, MailSettings $settings): Message
    {
        return Message::of(
            $email,
            "Reset your {$settings->appName} password",
            "Someone asked to reset the password for this address on {$settings->appName}.\n\n"
            . "To choose a new password, open this link within an hour:\n"
            . $settings->link('/reset/' . $token) . "\n\n"
            . "The link works once. If you did not ask for this, you can ignore this email: your password stays as it is.\n",
        );
    }

    public static function changed(string $email, MailSettings $settings): Message
    {
        return Message::of(
            $email,
            "Your {$settings->appName} password was changed",
            "The password for this address on {$settings->appName} was just changed, through a reset link.\n\n"
            . "If that was you, there is nothing more to do. If it was not, ask for a new reset link at\n"
            . $settings->link('/forgot-password') . "\n"
            . "and tell an organiser.\n",
        );
    }
}
