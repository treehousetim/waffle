<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * One email: plain text only, so nothing a visitor wrote is ever rendered as
 * HTML (docs/mail.md, 6.). Every address and the subject are checked here, so
 * no line break or control character reaches a header (5.).
 */
final readonly class Message
{
    public const int MAX_RECIPIENTS = 50;

    public const int MAX_SUBJECT = 200;

    /** @param list<string> $to */
    private function __construct(
        public array $to,
        public string $subject,
        public string $text,
        public ?string $replyTo,
    ) {
    }

    /**
     * @param string|list<mixed> $to
     *
     * @throws InvalidMessage
     */
    public static function of(string|array $to, string $subject, string $text, ?string $replyTo = null): self
    {
        $addresses = array_map(self::address(...), is_string($to) ? [$to] : $to);

        if ($addresses === [] || count($addresses) > self::MAX_RECIPIENTS) {
            throw InvalidMessage::because('An email needs between 1 and ' . self::MAX_RECIPIENTS . ' recipients.');
        }

        $subject = trim($subject);

        if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            throw InvalidMessage::because('An email needs a subject of one short line.');
        }

        // Lines kept, every other control character dropped, one final newline.
        $body = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace("\r\n", "\n", $text));

        if (trim($body) === '') {
            throw InvalidMessage::because('An email needs some text.');
        }

        return new self(array_values(array_unique($addresses)), $subject, rtrim($body) . "\n", $replyTo === null ? null : self::address($replyTo));
    }

    /** @return array{to:list<string>,subject:string,text:string,reply_to:?string} */
    public function toArray(): array
    {
        return ['to' => $this->to, 'subject' => $this->subject, 'text' => $this->text, 'reply_to' => $this->replyTo];
    }

    /**
     * @param array<mixed> $stored
     *
     * @throws InvalidMessage
     */
    public static function fromArray(array $stored): self
    {
        $to = $stored['to'] ?? null;
        $subject = $stored['subject'] ?? null;
        $text = $stored['text'] ?? null;
        $replyTo = $stored['reply_to'] ?? null;

        if (!is_array($to) || !array_is_list($to) || !is_string($subject) || !is_string($text) || ($replyTo !== null && !is_string($replyTo))) {
            throw InvalidMessage::because('That is not a stored message.');
        }

        return self::of($to, $subject, $text, $replyTo);
    }

    private static function address(mixed $value): string
    {
        $address = is_string($value) ? strtolower(trim($value)) : '';

        if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidMessage::because('That is not an email address.');
        }

        return $address;
    }
}
