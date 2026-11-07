<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Checks a webhook delivery signed with Svix's scheme, which Resend uses
 * (docs/mail.md, 13.): an HMAC-SHA256 of `<id>.<timestamp>.<body>`, keyed with
 * the base64 part of a `whsec_…` secret, as one of the `v1,<base64>` entries
 * of the signature header, and a timestamp within five minutes of now.
 */
final class SvixSignature
{
    public const int TOLERANCE_SECONDS = 300;

    /** @throws InvalidSignature */
    public static function verify(string $secret, string $id, string $timestamp, string $header, string $body, int $now): void
    {
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        if ($key === false || $key === '' || $id === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            throw new InvalidSignature('The delivery cannot be trusted.');
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        foreach (preg_split('/\s+/', trim($header)) ?: [] as $entry) {
            if (str_starts_with($entry, 'v1,') && hash_equals($expected, substr($entry, 3))) {
                return;
            }
        }

        throw new InvalidSignature('The delivery cannot be trusted.');
    }
}
