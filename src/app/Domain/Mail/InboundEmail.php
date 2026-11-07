<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use InvalidArgumentException;

/**
 * A message sent to the domain, as Resend's received-email API gives it,
 * turned into the Inbox's fields (docs/mail.md, "Mail sent to the domain"):
 * the sender's name from the From header, plain text (an HTML-only message is
 * turned into its text, never filed as markup: 16.), lengths capped.
 */
final class InboundEmail
{
    public const int MESSAGE_MAX = 20000;

    /**
     * @param array<string,mixed> $received
     *
     * @return array{name:string,email:string,to:string,subject:string,message:string}
     */
    public static function fields(array $received): array
    {
        $email = strtolower(trim(is_string($received['from'] ?? null) ? $received['from'] : ''));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The message has no sender address.');
        }

        $header = $received['headers']['from'] ?? '';
        $name = is_string($header) && preg_match('/^\s*"?([^"<]+?)"?\s*</', $header, $match) === 1 ? $match[1] : '';
        $to = is_array($received['to'] ?? null) ? implode(', ', array_filter($received['to'], 'is_string')) : '';
        $text = is_string($received['text'] ?? null) ? $received['text'] : '';

        if (trim($text) === '' && is_string($received['html'] ?? null)) {
            $text = self::textOf($received['html']);
        }

        $subject = is_string($received['subject'] ?? null) ? self::line($received['subject'], 200) : '';
        $message = trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $text)));

        return [
            'name' => self::line($name, 100),
            'email' => $email,
            'to' => self::line($to, 500),
            'subject' => $subject === '' ? '(no subject)' : $subject,
            'message' => $message === '' ? '(no text)' : mb_substr($message, 0, self::MESSAGE_MAX),
        ];
    }

    /**
     * The email to one reader of the Inbox about a message sent to the
     * domain, a reply going to its sender (docs/mail.md; 10.: nothing is sent
     * to the sender).
     *
     * @param array{name:string,email:string,to:string,subject:string,message:string} $fields
     */
    public static function notice(array $fields, string $reader, MailSettings $settings): Message
    {
        $from = $fields['name'] !== '' ? "{$fields['name']} <{$fields['email']}>" : $fields['email'];
        $subject = mb_substr(($fields['to'] !== '' ? "Email to {$fields['to']}: " : 'Email: ') . $fields['subject'], 0, Message::MAX_SUBJECT);

        return Message::of(
            $reader,
            $subject,
            "{$from} wrote" . ($fields['to'] !== '' ? " to {$fields['to']}" : '') . ".\n\n"
            . "Subject: {$fields['subject']}\n\n"
            . "{$fields['message']}\n\n"
            . "Reply to this email to answer them, or read it in the Inbox:\n"
            . $settings->link('/inbox') . "\n",
            $fields['email'],
        );
    }

    private static function textOf(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6])>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace("/\n{2,}/", "\n", trim($text));
    }

    private static function line(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, $max);
    }
}
