<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * A site's key (docs/forms.md, 2.): 256 random bits, given to the owner once
 * and kept only as its SHA-256.
 */
final class SiteKey
{
    /** @return array{0:string,1:string} [the key, its hash] */
    public static function issue(): array
    {
        $key = 'wsk_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$key, hash('sha256', $key)];
    }

    /** The hash to look a key up by, or null for anything that is not one. */
    public static function hash(string $key): ?string
    {
        return preg_match('/^wsk_[A-Za-z0-9_-]{43}$/', $key) === 1 ? hash('sha256', $key) : null;
    }
}
