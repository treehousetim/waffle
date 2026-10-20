<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * What the app knows of who sent a submission (docs/forms.md, 5.): a keyed
 * hash of their address with the day and the site, enough to count their
 * submissions today and nothing else. The address itself is never kept, and
 * the key is not in the database, so a copy of it cannot reverse the hash.
 */
final class Visitor
{
    public static function hash(string $address, string $day, int $siteId, string $key): string
    {
        return hash_hmac('sha256', $address . '|' . $day . '|' . $siteId, $key);
    }

    /**
     * The visitor's address, from the X-Visitor-Address header the site's
     * Caddy writes (Waffle Site's docs/waffle-api.md). Read only on a request
     * that carried the site's key, so a visitor cannot choose it; and never
     * X-Forwarded-For, which the app's own Caddy rewrites to the site
     * server's address, putting every visitor in one bucket (4.).
     */
    public static function address(string $visitorHeader, string $fallback): string
    {
        $address = trim($visitorHeader);

        return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : $fallback;
    }
}
