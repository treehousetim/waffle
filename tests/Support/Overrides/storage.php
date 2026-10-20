<?php

declare(strict_types=1);

/**
 * Storage, as the test container binds it (docs/storage.md): files are kept
 * in memory, never on the disk.
 */

use App\Domain\Ports\StoragePort;
use Tests\Support\Fakes\FakeStorage;

use function DI\factory;

return [
    StoragePort::class => factory(static fn (): StoragePort => new FakeStorage()),
];
