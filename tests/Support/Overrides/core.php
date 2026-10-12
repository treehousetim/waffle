<?php

declare(strict_types=1);

/**
 * The platform's ports, as the test container binds them.
 *
 * Every interface in src/app/Domain/Ports/ is bound to a fake in a fragment
 * here, one file per slice, mirroring src/config/container/. A port that
 * reaches the network is bound to its fake (tests/Support/Fakes/Fake<Port>)
 * constructed so that it THROWS on any call, so a test that forgets to set one
 * up fails loudly instead of quietly calling a real service; a test that needs
 * canned answers swaps in one that records calls and returns them, with
 * $this->swap(Port::class, new FakePort(...)).
 *
 * IdentityPort is the exception: it is left real, because Delight\Auth only
 * needs the database, and actingAs() signs in through it so the middleware
 * runs exactly as in production.
 */

use App\Domain\Ports\ClockPort;
use Tests\Support\Fakes\FrozenClock;

use function DI\factory;

return [
    ClockPort::class => factory(static fn (): ClockPort => new FrozenClock()),
];
