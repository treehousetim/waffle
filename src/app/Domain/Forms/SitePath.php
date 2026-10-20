<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * Where a form-encoded submission is sent back to (docs/forms.md, 9.): only
 * a plain path on the site, never a full URL, a protocol-relative one, or
 * anything that climbs; otherwise the home page.
 */
final class SitePath
{
    public static function or(mixed $raw, string $fallback = '/'): string
    {
        return is_string($raw) && preg_match('#^/(?!/)[A-Za-z0-9/_-]*$#', $raw) === 1 && !str_contains($raw, '//') ? $raw : $fallback;
    }
}
