<?php

declare(strict_types=1);

namespace App\Domain\Ports;

/**
 * Tells the site that published content changed (docs/content.md, "Change
 * notices"), so it rebuilds the pages those slugs touch. Called only from
 * the queue's worker, never from a request. Throws when the site could not
 * be told, so the queue tries again.
 */
interface SiteNotifierPort
{
    /** @param array<string,list<string>> $changes record type => slugs */
    public function notify(array $changes): void;
}
