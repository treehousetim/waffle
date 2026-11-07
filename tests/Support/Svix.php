<?php

declare(strict_types=1);

namespace Tests\Support;

/** Signing a webhook delivery as Svix (and so Resend) does (docs/mail.md, 13.). */
final class Svix
{
    /** The dummy secret: .env.testing's RESEND_WEBHOOK_SECRET. */
    public const string SECRET = 'whsec_dGVzdC13ZWJob29rLXNlY3JldC0xMjM0NTY3ODkw';

    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        $key = base64_decode(substr($secret, strlen('whsec_')), true);

        return 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", (string) $key, true));
    }
}
