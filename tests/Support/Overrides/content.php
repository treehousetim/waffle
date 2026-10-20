<?php

declare(strict_types=1);

/**
 * The content slice, as the test container binds it (docs/content.md): the
 * site is told nothing over the network.
 */

use App\Domain\Ports\SiteNotifierPort;
use Tests\Support\Fakes\FakeSiteNotifier;

use function DI\factory;

return [
    SiteNotifierPort::class => factory(static fn (): SiteNotifierPort => new FakeSiteNotifier()),
];
