<?php

declare(strict_types=1);

namespace App\Domain\Invitations;

/**
 * An invitation's link token (docs/invitations.md, 3.): 256 random bits as
 * 43 URL-safe characters, kept in the database only as its SHA-256.
 */
final class InvitationToken
{
    /** @return array{0:string,1:string} [the token for the link, its hash for the database] */
    public static function issue(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$token, self::hashOf($token)];
    }

    /** The hash to look a token up by, or null for anything that is not one. */
    public static function hash(string $token): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1 ? self::hashOf($token) : null;
    }

    private static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}
