<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domain\Ports\SiteNotifierPort;
use RuntimeException;

/** The site, as the tests see it: every notice it was sent, or a failure on demand. */
final class FakeSiteNotifier implements SiteNotifierPort
{
    /** @var list<array<string,list<string>>> */
    public array $sent = [];

    public bool $down = false;

    public function notify(array $changes): void
    {
        if ($this->down) {
            throw new RuntimeException('The site could not be reached.');
        }

        $this->sent[] = $changes;
    }
}
