<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Mail\MailSettings;
use App\Domain\Mail\Message;

/**
 * The email a form's submission sends to whoever may read the Inbox
 * (docs/forms.md; docs/mail.md). One email per reader, to that reader alone,
 * so no reader's address is shown to another (12.); a reply goes to the
 * sender, when the form has a usable address, and nothing is ever sent to it
 * (10.).
 */
final class SubmissionNotice
{
    /**
     * @param array<string,mixed> $fields  what the form's parser accepted
     * @param list<string>        $readers the addresses that may read the Inbox
     *
     * @return list<Message>
     */
    public static function messages(string $siteName, string $formLabel, array $fields, array $readers, MailSettings $settings): array
    {
        $name = is_string($fields['name'] ?? null) ? mb_substr(trim($fields['name']), 0, 100) : '';
        $sender = is_string($fields['email'] ?? null) && filter_var($fields['email'], FILTER_VALIDATE_EMAIL) !== false ? $fields['email'] : null;
        $subject = $formLabel . ' form: ' . ($name !== '' ? 'a message from ' . $name : 'a new message');

        $lines = [];
        $blocks = [];

        foreach ($fields as $field => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $label = ucfirst(str_replace('_', ' ', (string) $field));
            $value = (string) $value;

            if (str_contains($value, "\n")) {
                $blocks[] = "{$label}:\n{$value}";
            } else {
                $lines[] = "{$label}: {$value}";
            }
        }

        $text = "{$siteName} received a message through its {$formLabel} form.\n\n"
            . implode("\n", $lines) . "\n"
            . implode('', array_map(static fn (string $block): string => "\n{$block}\n", $blocks)) . "\n"
            . ($sender !== null ? 'Reply to this email to answer ' . ($name !== '' ? $name : 'the sender') . ', or read it in the Inbox:' : 'Read it in the Inbox:') . "\n"
            . $settings->link('/inbox') . "\n";

        return array_map(static fn (string $reader): Message => Message::of($reader, $subject, $text, $sender), $readers);
    }
}
