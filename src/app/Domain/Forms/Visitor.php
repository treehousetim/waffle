<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * What the app knows of who sent a submission (docs/forms.md, 5.): a hash of
 * their address with the day and the site, enough to count their
 * submissions today and nothing else. The address itself is never kept.
 */
final class Visitor
{
    public static function hash(string $address, string $day, string $siteSecret): string
    {
        return hash('sha256', $address . '|' . $day . '|' . $siteSecret);
    }

    /**
     * The visitor's address from X-Forwarded-For as the site's server set
     * it: the first entry, which the site's Caddy wrote (Waffle Site's
     * docs/waffle-api.md).
     */
    public static function address(string $forwardedFor, string $fallback): string
    {
        $first = trim(explode(',', $forwardedFor)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : $fallback;
    }
}
