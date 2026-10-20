<?php

declare(strict_types=1);

namespace App\Libraries\Content;

use App\Domain\Ports\SiteNotifierPort;
use RuntimeException;

/**
 * SiteNotifierPort when no site is configured (`SITE_REPOSITORY` or
 * `SITE_DISPATCH_TOKEN` unset). Outside production a notice is simply not
 * sent: a developer's machine has no site to rebuild. In production the job
 * fails, so the queue page shows that the site is not being told.
 */
final readonly class NoSiteToNotify implements SiteNotifierPort
{
    public function __construct(private bool $production)
    {
    }

    public function notify(array $changes): void
    {
        if ($this->production) {
            throw new RuntimeException('No site to notify: set SITE_REPOSITORY and SITE_DISPATCH_TOKEN (docs/content.md).');
        }
    }
}
